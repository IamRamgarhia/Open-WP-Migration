=== Open Migration - Unlimited Site Transfer & Backup ===
Contributors: openmigration
Tags: migration, backup, clone, transfer, restore
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move, clone, back up and restore a WordPress site with no upload size limit. URLs, paths and table prefixes are converted automatically.

== Description ==

Open Migration copies a complete WordPress site - database, themes, plugins, uploads - into a single `.zip` archive, and restores that archive onto any other WordPress install.

Both the export and the import run in small batches over AJAX. Nothing is held in memory all at once and no single request runs long enough to time out, so multi-gigabyte sites work on ordinary shared hosting.

= Restoring onto a brand new WordPress =

You do not have to prepare the destination. Install WordPress, install this plugin, upload the archive, and the plugin works out the differences for you:

* **Site URL** - every reference to the old domain is rewritten to the new one, including inside PHP-serialised option values, JSON-escaped block markup, and URL-encoded links.
* **Server paths** - absolute paths stored by page builders and cache plugins are updated to the new document root.
* **Table prefix** - if the old site used `wp_abc_` and the new one uses `wp_`, every table, option name and user meta key is converted during the restore.
* **Serialised data** - values are unserialised, replaced and re-serialised so string length prefixes stay correct. A naive find-and-replace corrupts these; this does not.

= Migrating before you switch DNS =

Most real migrations happen while the domain still points at the old host, so you work on the new server through a temporary address - `site.yourhost.com`, a staging subdomain, or a raw IP.

That is fine. Import onto the temporary address and the site works there straight away. Then, once you have pointed DNS and the real domain loads the new server, open the **Change Domain** tab and enter the real address. Every reference is rewritten in one pass.

This step cannot be automatic. WordPress only ever sees the hostname a visitor requested, so it has no way of knowing that DNS was switched - it will keep sending visitors back to the temporary address until you tell it otherwise. The Change Domain tab is also the right tool for moving from `http` to `https`, or for renaming a domain later on.

= Direct site-to-site transfer =

Instead of downloading and re-uploading an archive, the source site can issue a one-time token. Paste the token into the destination site and it pulls the archive directly over the WordPress REST API, then runs the same automatic conversion.

= What gets exported =

* The full database for this site's table prefix
* `wp-content` - themes, plugins, uploads, languages and anything else you have put there

Transient caches (`wp-content/cache`, `wp-content/upgrade`) and other plugins' backup folders are skipped, because restoring them is pointless and they are often larger than the site itself.

= What is not touched =

`wp-config.php`, WordPress core files and your database credentials are never included in the archive and never overwritten. The destination keeps its own configuration.

= Who makes this =

Open Migration is built and maintained by [DiceCodes](https://dicecodes.com/). It is free and open source under GPLv2, with no paid tier, no upsells and no data collection of any kind.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/open-migration`, or install it from the Plugins screen.
2. Activate the plugin.
3. Open **Open Migration** in the admin menu.

To move a site:

1. On the old site, open the **Export** tab and click **Start Export**, then download the `.zip`.
2. On the new site, open the **Import** tab and drop that `.zip` onto the upload area.
3. Wait for the import to finish, then log in with the **old site's** username and password.
4. Go to **Settings - Permalinks** and click **Save Changes** once.

== Frequently Asked Questions ==

= Is there really no upload size limit? =

The archive is sliced into small pieces in the browser and reassembled on the server, so your host's `upload_max_filesize` and `post_max_size` limits do not apply. The chunk size is chosen automatically from your server's own limits.

= Do I need to change any URLs after importing? =

No. The conversion runs automatically as part of the import and reports how many database rows it changed.

= Why am I logged out at the end of an import? =

Restoring the database replaces the destination's user accounts with the ones from the archive, which invalidates your current session. Log back in with the username and password from the **original** site. If your account exists on both sites with administrator rights, the plugin signs you back in automatically.

= I imported on my host's temporary domain. What happens when DNS points to the real one? =

Nothing changes by itself - the database still contains the temporary address, so WordPress redirects visitors back to it. Once the real domain loads the new server, open the **Change Domain** tab, enter the real address and run it. That rewrites everything in one pass, including serialised and JSON-escaped references.

Do it in that order. Changing the address before DNS resolves will leave the site unreachable at both addresses.

= Can I change the domain again later, or move from http to https? =

Yes. The **Change Domain** tab works at any time and is not tied to an import.

= Can I import a site that used a different table prefix? =

Yes. The prefix is recorded in the archive and converted during the restore, including the prefixed keys inside `options` and `usermeta`.

= Does this work on multisite? =

Not yet. Multisite networks are detected and refused rather than partially migrated.

= What are the server requirements? =

PHP 7.2 or newer with the ZipArchive extension enabled, and enough free disk space for the archive. The Export tab shows a server check before you start.

= Where are backups stored, and can anyone download them? =

In `wp-content/uploads/owpm-backups/`. That directory is protected with deny-all rules for Apache and IIS, carries an index file, and archive names include 32 random characters. Downloads are streamed through WordPress only after a capability and nonce check, so the folder never has to be publicly readable - which also keeps it safe on Nginx, where `.htaccess` files are ignored.

= Does the plugin connect to any external service? =

Only when you use the Site-to-Site tab, and only to the address you supply. Generating a token makes one request to your own site's REST API to confirm it is reachable. Pulling a site makes a request to the source site whose token you pasted. No data is sent to the plugin author or any third party, and no analytics or tracking of any kind is included.

= Why are some AJAX actions registered for logged-out requests? =

Restoring a database replaces the destination's user accounts and session tokens. The moment that happens, the administrator running the import is no longer recognised as logged in - so the remaining steps of their own import would be rejected, leaving the site half-restored. Those steps are therefore reachable without a login cookie.

They are not open to the public. Every one of them requires either a valid nonce plus the `manage_options` capability, or a 64-character random key that only exists while an import an administrator explicitly started is in progress. Only a SHA-256 hash of that key is stored on disk, it is compared with `hash_equals()`, it expires after six hours, and it is deleted as soon as the import finishes or is cancelled. The matching file identifier is required as well.

= What else does the plugin do to keep things safe? =

* Every administrative action checks a nonce and the `manage_options` capability.
* Archive entries are validated one by one before extraction, so a crafted `.zip` cannot write outside `wp-content` (zip slip). Absolute paths, `..` segments and anything outside the expected folders are refused rather than sanitised.
* Identifiers used to build file paths are reduced to `[A-Za-z0-9_-]`. `sanitize_text_field()` alone is not sufficient for this, because it leaves `../` intact.
* Table names are validated against a strict pattern before being used in a query, and every value is bound through `$wpdb->prepare()`.
* Backups are streamed through WordPress after a permission check rather than being served as directly reachable files.
* The address entered in Change Domain is fully validated - scheme, hostname and path - instead of being silently corrected into something that merely looks valid.

= Does the plugin write anything outside its own folder? =

Only backups, into `wp-content/uploads/owpm-backups/`. Nothing is written to the plugin directory, to `mu-plugins`, or anywhere else. Restoring an archive of course writes the files that archive contains, into `wp-content`.

== Screenshots ==

1. Export tab with the server check and progress reporting.
2. Import tab with drag-and-drop upload.
3. Direct site-to-site transfer using a one-time token.
4. Changing the site domain after DNS is pointed at the new server.
5. Managing existing backups.

== Changelog ==

= 1.3.5 =
* Added a documentation link to each tab, deep-linked to the section covering that tab, so help opens on the relevant part rather than the top of a long page.

= 1.3.4 =
* Changed: confirmations are now shown in a styled in-page dialog instead of the browser's native alert. The native one is titled with the raw hostname, which on a temporary host reads as something like "aquamarine-chimpanzee-231969.hostingersite.com says" - not what anyone wants to see immediately before overwriting a site. The new dialog is keyboard accessible, focuses Cancel by default so Enter cannot destroy anything, closes on Escape, and names exactly what is about to change.
* Added author attribution: plugin headers now carry the author and plugin URIs, a credit line appears at the bottom of the plugin's own admin screen, and a documentation link is added to its row on the Plugins screen. Nothing is added to the public-facing site and no notices appear anywhere else in the dashboard.

= 1.3.3 =
* Removed an unnecessary jQuery dependency. The admin script uses `fetch` and plain DOM APIs, so jQuery was being loaded on the plugin's screen for nothing.
* Added silence files to the plugin's subdirectories so no server can list their contents.
* Added a command-line guard to the development version-bump script, so it cannot be executed over HTTP if the source repository is ever served directly.
* Documented, in the code and in this readme, why a few AJAX actions are reachable without a login cookie and how they are secured.

= 1.3.2 =
* Security/correctness: the Change Domain field accepted malformed addresses and silently corrected them into something that looked valid. `htp://typo.com` became `https://htp//typo.com`, `ex ample.com` became `https://ex%20ample.com`, and a bare `example` was treated as a domain. Committing any of those would have rewritten the whole database to an address that does not resolve, taking the site offline. Addresses are now validated properly: the scheme must be http or https, the hostname must be a real hostname, an IP, or localhost, and whitespace, relative path segments and non-http schemes are refused with a specific explanation.
* Changed: the Change Domain tab no longer guesses a previous address from your content. Inferring one from post GUIDs, from a staging hostname, or from domains appearing in post content was tested against real sites, and each approach put a wrong domain first: a domain the site had long abandoned, the host's own domain, and a third-party site whose images had been hotlinked. Only addresses actually recorded somewhere are offered now - an import this plugin performed, `siteurl`/`home` rows that wp-config.php is overriding, or the metadata inside a backup on the server.

= 1.3.1 =
* Fixed: the Change Domain tab only suggested a previous address when an import had been run by this plugin, so on most sites the field was simply empty. It now detects the previous address from several sources: an import this plugin performed, the raw `siteurl`/`home` rows in the database (which still hold the live domain when wp-config.php overrides them, as staging sites commonly do), and the metadata inside any backup archive on the server.
* Changed: every detected address is offered as a labelled, clickable suggestion showing where it was found, rather than a single hidden guess. The most trusted one pre-fills the field.
* Changed: when nothing can be detected, the tab now explains why instead of showing an empty field with no comment.

= 1.2.0 =
* Fixed: the export archive included the backup directory itself, so each export absorbed every earlier backup and grew without limit.
* Fixed: import file identifiers were not restricted to safe characters, allowing a crafted request to write outside the backups directory.
* Fixed: archive entries were extracted without validation, allowing a crafted `.zip` to write outside `wp-content` (zip slip).
* Fixed: `database.sql` and `metadata.json` were extracted into the web root, where the database dump was publicly reachable. They now go to a private working directory.
* Fixed: search and replace used only the last primary key column, which could overwrite unrelated rows in tables with a composite key such as `wp_term_relationships`.
* Fixed: exports and imports read every table in the database rather than only those matching this site's prefix, affecting installs that share a database.
* Fixed: backup list sorting parsed a localised date string instead of the file timestamp, producing the wrong order in many locales.
* Fixed: directory protection used Apache 2.2 syntax only. Downloads now stream through WordPress after a permission check and the folder stays private on Apache, Nginx and IIS.
* Fixed: failed database statements during an import were silently ignored and still reported as success.
* Fixed: the database dump was split on `;` followed by a newline, which corrupted multi-line statements. An explicit statement delimiter is now used.
* Fixed: `WP_Filesystem()` failure was not checked before use, causing a fatal error on hosts requiring FTP credentials.
* Fixed: drag-and-drop was described in the interface but never implemented.
* Fixed: site-to-site transfer issued its token before the export had finished, so the destination could pull a truncated archive.
* Added: automatic table prefix conversion, including prefixed keys in `options` and `usermeta`.
* Added: URL rewriting now also covers JSON-escaped, URL-encoded, protocol-relative and scheme-changed references.
* Added: resumable database export and import, and resumable search and replace, so large sites cannot time out.
* Added: server compatibility check on the Export tab.
* Added: charset and collation fallback when restoring a MySQL 8 dump onto an older server.
* Added: progress reporting, error surfacing and a post-import checklist.

= 1.3.0 =
* Added: **Change Domain** tab. Switch the site address after DNS is pointed at the new server, or move from `http` to `https`, at any time. Rewrites every reference in resumable batches.
* Added: the Change Domain tab remembers the address an imported site came from and offers it as a one-click suggestion, so the domain never has to be retyped from memory.
* Added: a DNS pre-flight check. Before committing, the plugin proves the new address really resolves to this server by requesting it with a one-time token and looking for its own marker. Changing the address before DNS propagates is the one mistake that takes a site offline at both addresses.
* Added: the confirmation dialog now spells out the exact old and new address, and warns more strongly when the DNS check failed or was never run.
* Security: the import session key is stored only as a SHA-256 hash, so reading the session file cannot yield a usable credential.
* Fixed: the database export fetched a fixed 500 rows per batch regardless of row size, which could exhaust memory on sites with very large post content. Batch size is now derived from the table's average row length.
* Added: transients are excluded from the export. They are a cache, so shipping them only bloated the archive and restored the source site's stale cached data.
* Added: a warning before importing an archive created on a newer WordPress or PHP version than the destination runs.
* Changed: the options table is restored last, so the destination keeps a working plugin list for the whole restore. This removed the need for the temporary `mu-plugins` loader, which is no longer created.
* Changed: the interface now keeps status and the primary action visible without scrolling; step-by-step guidance and the included/excluded lists moved into collapsible sections.

= 1.1.4 =
* Interface refinements.

= 1.1.1 =
* Added dynamic instructions.
* Refined interface spacing and typography.

= 1.1.0 =
* Chunked database export to avoid memory limits.
* Switched to standard `.zip` archives.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
Adds a Change Domain tool with a DNS safety check, hardens how the import session key is stored, and fixes a memory problem when exporting sites with very large posts.

= 1.2.0 =
Important security and correctness release. Fixes two file-write vulnerabilities in the import routine, stops exports from absorbing previous backups, and adds automatic table prefix conversion. Upgrading is strongly recommended.
