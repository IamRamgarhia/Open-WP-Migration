=== Open Migration - Unlimited Site Transfer & Backup ===
Contributors: dicecodes
Tags: migration, backup, clone, transfer, restore
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.3.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate, clone, back up and restore a WordPress site with no upload size limit. URLs, paths and table prefixes are converted automatically.

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

1. In the WordPress admin, go to **Plugins - Add New**, search for **Open Migration**, and click **Install Now**. To install manually instead, upload the plugin folder to `/wp-content/plugins/open-migration-unlimited-site-transfer-backup/`.
2. Click **Activate**.
3. Open **Open Migration** in the admin menu.

Install it on **both** sites - the one you are moving from and the one you are moving to.

= Moving a site to a new host =

1. On the **old** site, open the **Export** tab. Check the server report at the top, then click **Start Export** and wait. Large sites take a while; the progress bar keeps updating and the export resumes by itself if a request is interrupted.
2. Download the finished `.zip`.
3. On the **new** site - a plain, freshly installed WordPress is fine - open the **Import** tab and drop the `.zip` onto the upload area.
4. Confirm the warning. The import replaces everything on the destination.
5. When it finishes, log in with the **old site's** username and password. The old site's accounts have replaced the new site's.
6. Go to **Settings - Permalinks** and click **Save Changes** once, to rebuild the rewrite rules.

URLs, server paths and the table prefix are converted for you during step 4. There is nothing to search and replace afterwards.

= Moving a site before DNS is switched =

This is the usual case when changing hosts, and it needs one extra step at the end.

1. Export and import exactly as above, using whatever temporary address the new host gave you - `site.yourhost.com`, a staging subdomain, or an IP.
2. Check the site works at that temporary address.
3. Point your domain's DNS at the new server and wait for it to resolve.
4. Once the real domain loads the new server, open the **Change Domain** tab, enter the real address and run it.

Do it in that order. Running Change Domain before DNS resolves leaves the site unreachable at both addresses. The tab shows the current address and runs a DNS check first, so it can warn you before anything is written.

= Copying a site directly, without downloading anything =

1. On the **source** site, open the **Site-to-Site** tab and generate a one-time token.
2. On the **destination** site, paste the token into the same tab and start the pull.

The destination fetches the archive straight from the source over the REST API and then runs the same automatic conversion. Nothing passes through your computer, which is useful on a slow connection or with a very large site.

= Making a backup =

Run an export and keep the `.zip`. Restore it later on the same site or any other one - the **Backups** tab lists everything stored on the server, with download and delete buttons.

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

In `wp-content/uploads/open-migration-unlimited-site-transfer-backup/`. That directory is protected with deny-all rules for Apache and IIS, carries an index file, and archive names include 32 random characters. Downloads are streamed through WordPress only after a capability and nonce check, so the folder never has to be publicly readable - which also keeps it safe on Nginx, where `.htaccess` files are ignored.

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

Only backups, into `wp-content/uploads/open-migration-unlimited-site-transfer-backup/`. Nothing is written to the plugin directory, to `mu-plugins`, or anywhere else. Restoring an archive of course writes the files that archive contains, into `wp-content`.

= How big a site can this handle? =

There is no fixed ceiling. Both the export and the import run in small batches, and each batch stops before the server's time limit and picks up where it left off. Multi-gigabyte sites work on ordinary shared hosting. The practical limit is disk space: the server needs room for the archive alongside the site itself, and the Export tab checks this before it starts.

= Does it work on shared hosting? =

Yes, and that is what it is designed for. It does not need shell access, WP-CLI, cron, or any raised PHP limit. If your host can run WordPress and has the ZipArchive extension, it works.

= How long does a migration take? =

Most small sites finish in a few minutes. Time scales with the number of files and the size of the database rather than with disk size alone, so a site with tens of thousands of small media files takes longer than a single large video. Progress is reported throughout, and closing the tab does not corrupt anything - the export or import resumes.

= Can I use this to create a staging or test copy? =

Yes. Export the live site and import it onto a second install at a different address. The URL conversion means the copy works immediately at its own address without any manual editing.

= Can I move a site from localhost to a live server, or the other way round? =

Yes, in both directions. Local addresses like `http://localhost/mysite` are handled the same as any other, including the port number if you use one.

= Do I need to deactivate my other plugins first? =

No. The archive contains your plugins as they were, and they come back in the same state on the destination.

= What happens if an import fails partway through? =

The destination is left with whatever had been restored up to that point, so start the import again rather than leaving it. The **Abort** button cleans up the working files and the session key. Because a failed import can leave a site in a half-restored state, take a backup of the destination first if it holds anything you care about.

= Will my media library and page builder content survive? =

Yes. Media files are copied as they are, and the URL conversion rewrites references inside serialised option values and JSON-escaped block markup, which is where page builders such as Elementor, Divi and WPBakery keep their data. A plain database search and replace corrupts serialised values; this does not.

= Is it really free? =

Yes. There is no paid tier, no feature held back, no upsell, and no size limit. It is GPLv2 open source.

== Screenshots ==

1. Export tab with the server check and progress reporting.
2. Import tab with drag-and-drop upload.
3. Direct site-to-site transfer using a one-time token.
4. Changing the site domain after DNS is pointed at the new server.
5. Managing existing backups.

== Changelog ==

= 1.3.12 =
* Fixed: sites that relocate the uploads folder outside wp-content (via the UPLOADS constant or a custom upload path) produced a backup with no media in it. Uploads are now archived as a second root and restored to wherever the destination keeps them, so a move between two differently laid out installs works in both directions.
* Changed: every path the plugin needs is now resolved through one small set of helpers in class-owpm-fs.php. Locations come from wp_upload_dir() and plugin_dir_path(); the content directory and site root are read in exactly one place each, with the reason documented there.

= 1.3.11 =
* Fixed: the text domain now matches the plugin slug, as the directory requires. All 262 translatable strings were updated.
* Changed: the main plugin file and the backup folder are now named after the plugin slug as well, so every reference is consistent.

= 1.3.10 =
* Changed: the autoload value written during a restore now matches whatever the restored options table already uses. WordPress 6.6 replaced 'yes'/'no' in that column with 'on'/'off', and hardcoding either would eventually be wrong.

= 1.3.9 =
* Restored the Plugin URI header, now that the documentation site is published. It points at the plugin's own page and the Author URI points at the author's, which is the distinction the directory requires.

= 1.3.8 =
* Fixed: the server check assembled the uploads path from WP_CONTENT_DIR as a fallback, which is wrong on installs that move the uploads directory. It now resolves through wp_upload_dir() in every case.
* Changed: backups are now stored in wp-content/uploads/open-migration-unlimited-site-transfer-backup/ rather than a separate folder name, matching the plugin slug.
* Fixed: the Contributors line now names the WordPress.org account that owns the plugin.

= 1.3.7 =
* Fixed: the plugin and author URI headers held the same address. The directory requires them to differ, since one describes the plugin and the other the author. Only the author URI is declared now.

= 1.3.6 =
* Changed: the site-to-site token is now a plain, readable, delimited string instead of base64. It was only ever transport encoding, but the directory guidelines discourage anything resembling obfuscation, and a user should be able to read what they are pasting between two sites.
* Changed: after the database restore the plugin now signs the operator straight back in and issues a fresh nonce, so the remaining import steps run fully authenticated. The one-time key is now only a fallback for archives that do not contain the operator's account.

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

= 1.3.12 =
Fixes a bug that produced a backup with no media on sites that keep their uploads folder outside wp-content. Recommended for anyone on such a setup.

= 1.3.0 =
Adds a Change Domain tool with a DNS safety check, hardens how the import session key is stored, and fixes a memory problem when exporting sites with very large posts.

= 1.2.0 =
Important security and correctness release. Fixes two file-write vulnerabilities in the import routine, stops exports from absorbing previous backups, and adds automatic table prefix conversion. Upgrading is strongly recommended.
