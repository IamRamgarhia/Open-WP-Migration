<?php
/**
 * Serialisation-safe search & replace across the site's own tables.
 *
 * Runs in resumable batches so a large database cannot exhaust the request time,
 * and updates rows using the full primary key (which may be composite, as on
 * wp_term_relationships) rather than a single guessed column.
 *
 * @package Open_Migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OWPM_Search_Replace {

	/** Column types we never touch, because replacing inside them corrupts data. */
	private static $binary_types = array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' );

	/**
	 * Build every old->new string pair needed to move a site between two URLs.
	 *
	 * A URL is not stored in the database in one single form. It appears plain,
	 * JSON-escaped inside block markup and widget settings (http:\/\/old.com),
	 * URL-encoded inside redirects and oEmbed caches, and protocol-relative
	 * (//old.com). Replacing only the plain form leaves a half-migrated site.
	 *
	 * @param string $old_url  Previous site URL.
	 * @param string $new_url  New site URL.
	 * @param string $old_home Previous home URL, when it differs from site URL.
	 * @param string $new_home New home URL.
	 * @param string $old_path Previous absolute server path.
	 * @param string $new_path New absolute server path.
	 *
	 * @return array[] List of [search, replace] pairs, de-duplicated.
	 */
	public static function build_url_pairs( $old_url, $new_url, $old_home = '', $new_home = '', $old_path = '', $new_path = '' ) {
		$pairs = array();

		$old_url = untrailingslashit( (string) $old_url );
		$new_url = untrailingslashit( (string) $new_url );

		if ( $old_url && $new_url && $old_url !== $new_url ) {
			$pairs[] = array( $old_url, $new_url );
			$pairs[] = array( str_replace( '/', '\\/', $old_url ), str_replace( '/', '\\/', $new_url ) );
			$pairs[] = array( rawurlencode( $old_url ), rawurlencode( $new_url ) );

			$old_host = wp_parse_url( $old_url, PHP_URL_HOST );
			$new_host = wp_parse_url( $new_url, PHP_URL_HOST );

			if ( $old_host && $new_host && $old_host !== $new_host ) {
				// Protocol-relative references: //old.example.com/...
				$pairs[] = array( '//' . $old_host, '//' . $new_host );
			}

			if ( $old_host && $new_host && $old_host === $new_host ) {
				// Same host, scheme changed (http -> https).
				$old_scheme = wp_parse_url( $old_url, PHP_URL_SCHEME );
				$new_scheme = wp_parse_url( $new_url, PHP_URL_SCHEME );

				if ( $old_scheme && $new_scheme && $old_scheme !== $new_scheme ) {
					$pairs[] = array( $old_scheme . '://' . $old_host, $new_scheme . '://' . $new_host );
				}
			}
		}

		$old_home = untrailingslashit( (string) $old_home );
		$new_home = untrailingslashit( (string) $new_home );

		if ( $old_home && $new_home && $old_home !== $new_home && $old_home !== $old_url ) {
			$pairs[] = array( $old_home, $new_home );
			$pairs[] = array( str_replace( '/', '\\/', $old_home ), str_replace( '/', '\\/', $new_home ) );
		}

		// Absolute server paths stored by page builders and cache plugins.
		$old_path = untrailingslashit( str_replace( '\\', '/', (string) $old_path ) );
		$new_path = untrailingslashit( str_replace( '\\', '/', (string) $new_path ) );

		if ( $old_path && $new_path && $old_path !== $new_path ) {
			$pairs[] = array( $old_path, $new_path );
		}

		$seen  = array();
		$final = array();

		foreach ( $pairs as $pair ) {
			if ( '' === $pair[0] || $pair[0] === $pair[1] ) {
				continue;
			}

			$key = $pair[0] . "\0" . $pair[1];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$final[]      = $pair;
		}

		return $final;
	}

	/**
	 * Run a full search & replace in one call.
	 *
	 * Convenience wrapper around run_batch() for small sites and CLI use.
	 *
	 * @return array{rows:int,tables:int} Summary of what changed.
	 */
	public static function run( $old_string, $new_string ) {
		$tables = OWPM_FS::get_site_tables();
		$state  = array(
			'table_index' => 0,
			'offset'      => 0,
			'rows'        => 0,
			'done'        => false,
		);

		$guard = 0;

		// A large budget so each call runs to completion instead of yielding; the
		// guard is only a backstop against a pathological table.
		while ( ! $state['done'] && $guard < 10000 ) {
			$state = self::run_batch( $old_string, $new_string, $tables, $state, HOUR_IN_SECONDS );
			$guard++;
		}

		return array(
			'rows'   => (int) $state['rows'],
			'tables' => count( $tables ),
		);
	}

	/**
	 * Process as much as fits inside the time budget, then hand back the position.
	 *
	 * @param string   $old_string String to find.
	 * @param string   $new_string Replacement.
	 * @param string[] $tables     Tables to walk.
	 * @param array    $state      {table_index, offset, rows, done}.
	 * @param int      $budget     Seconds before yielding. 0 yields after the first
	 *                             page; the same convention as every other batch
	 *                             loop in the plugin.
	 *
	 * @return array Updated state.
	 */
	public static function run_batch( $old_string, $new_string, $tables, $state, $budget = 15 ) {
		global $wpdb;

		$state = wp_parse_args(
			(array) $state,
			array(
				'table_index' => 0,
				'offset'      => 0,
				'rows'        => 0,
				'done'        => false,
			)
		);

		$old_string = (string) $old_string;
		$new_string = (string) $new_string;

		if ( '' === $old_string || $old_string === $new_string ) {
			$state['done'] = true;
			return $state;
		}

		$tables    = array_values( (array) $tables );
		$total     = count( $tables );
		$started   = microtime( true );
		$page_size = 200;
		$iteration = 0;

		while ( $state['table_index'] < $total ) {
			// Checked at the top so the early `continue` paths below cannot bypass
			// it. This loop visits every table on the site for each replacement
			// pair, and most tables exit through one of those paths, so without
			// this the request could run well past its budget.
			// Must return rather than break: breaking would fall through to
			// $state['done'] = true and silently skip the remaining tables.
			if ( $iteration++ > 0 && ( microtime( true ) - $started ) > $budget ) {
				return $state;
			}

			$table   = $tables[ $state['table_index'] ];
			$escaped = OWPM_FS::esc_table( $table );

			if ( ! $escaped ) {
				$state['table_index']++;
				$state['offset'] = 0;
				continue;
			}

			$schema = self::describe( $escaped );

			// Without a primary key we cannot target a row for update safely.
			if ( empty( $schema['primary'] ) || empty( $schema['searchable'] ) ) {
				$state['table_index']++;
				$state['offset'] = 0;
				continue;
			}

			$where = array();
			$args  = array();

			foreach ( $schema['searchable'] as $column ) {
				$where[] = '`' . str_replace( '`', '``', $column ) . '` LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $old_string ) . '%';
			}

			$args[] = $page_size;
			$args[] = (int) $state['offset'];

			// This pagination resumes across separate requests and the offset only
			// advances past rows we deliberately left alone, so the row order has to
			// be stable between calls.
			$order_by = OWPM_FS::order_by_clause( $escaped );

			$sql = "SELECT * FROM {$escaped} WHERE " . implode( ' OR ', $where ) . $order_by . ' LIMIT %d OFFSET %d';

			// $sql is assembled from validated identifiers only; every value is bound
			// through prepare() via $args.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

			if ( empty( $rows ) ) {
				$state['table_index']++;
				$state['offset'] = 0;
				continue;
			}

			$unchanged = 0;

			foreach ( $rows as $row ) {
				$update = array();

				foreach ( $row as $column => $value ) {
					// The primary key identifies the row; never rewrite it.
					if ( in_array( $column, $schema['primary'], true ) ) {
						continue;
					}

					if ( ! in_array( $column, $schema['searchable'], true ) ) {
						continue;
					}

					$replaced = self::recursive_replace( $old_string, $new_string, $value );

					if ( $replaced !== $value ) {
						$update[ $column ] = $replaced;
					}
				}

				if ( empty( $update ) ) {
					$unchanged++;
					continue;
				}

				$conditions = array();
				foreach ( $schema['primary'] as $key_column ) {
					// A NULL primary key component means we cannot identify the row.
					if ( ! array_key_exists( $key_column, $row ) || is_null( $row[ $key_column ] ) ) {
						$conditions = array();
						break;
					}
					$conditions[ $key_column ] = $row[ $key_column ];
				}

				if ( empty( $conditions ) ) {
					$unchanged++;
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->update( $table, $update, $conditions );

				if ( false === $result ) {
					$unchanged++;
				} else {
					$state['rows'] = (int) $state['rows'] + 1;
				}
			}

			// Updated rows drop out of the LIKE result set, so the offset only needs
			// to skip the rows we deliberately left in place.
			if ( count( $rows ) < $page_size ) {
				$state['table_index']++;
				$state['offset'] = 0;
			} else {
				$state['offset'] = (int) $state['offset'] + $unchanged;
			}

		}

		$state['done'] = true;

		return $state;
	}

	/**
	 * Primary key columns and the columns worth searching, for one table.
	 */
	private static function describe( $escaped_table ) {
		$columns    = OWPM_FS::table_columns( $escaped_table );
		$searchable = array();

		foreach ( $columns['all'] as $field ) {
			$type      = isset( $columns['types'][ $field ] ) ? $columns['types'][ $field ] : '';
			$base_type = preg_replace( '/\(.*$/', '', $type );
			$base_type = trim( str_replace( array( 'unsigned', 'zerofill' ), '', $base_type ) );

			// Replacing inside binary columns corrupts them.
			if ( in_array( $base_type, self::$binary_types, true ) ) {
				continue;
			}

			// Numeric, date and enum columns can never contain a URL.
			if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)$/', $base_type ) ) {
				$searchable[] = $field;
			}
		}

		return array(
			'primary'    => $columns['primary'],
			'searchable' => $searchable,
		);
	}

	/**
	 * Replace inside strings, arrays and objects while keeping PHP serialisation valid.
	 */
	private static function recursive_replace( $search, $replace, $data ) {
		if ( is_string( $data ) ) {
			if ( '' === $data ) {
				return $data;
			}

			if ( is_serialized( $data ) ) {
				// allowed_classes => false prevents object injection from untrusted dumps.
				$unserialized = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, PHPCompatibility.FunctionUse.NewFunctionParameters

				// serialize(false) is the one case where false is a valid result.
				if ( false !== $unserialized || 'b:0;' === $data ) {
					$updated = self::recursive_replace( $search, $replace, $unserialized );
					return serialize( $updated ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				}

				// Corrupt serialised data: leave it untouched rather than break it further.
				return $data;
			}

			return str_replace( $search, $replace, $data );
		}

		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $key => $value ) {
				$new_key            = is_string( $key ) ? str_replace( $search, $replace, $key ) : $key;
				$result[ $new_key ] = self::recursive_replace( $search, $replace, $value );
			}
			return $result;
		}

		if ( is_object( $data ) ) {
			$result = new stdClass();
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$new_key            = is_string( $key ) ? str_replace( $search, $replace, $key ) : $key;
				$result->{$new_key} = self::recursive_replace( $search, $replace, $value );
			}
			return $result;
		}

		return $data;
	}
}
