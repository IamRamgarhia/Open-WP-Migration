=== Open Migration - Unlimited Site Transfer & Backup ===
Contributors: dicecodes
Tags: migration, backup, clone, transfer, restore
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.6.10
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

= A restore point before anything changes =

An import replaces the site it runs on, so Open Migration takes a restore point
first and offers it back if anything goes wrong — from the error screen, or from
the plugin's own screen later.

The database side is a rename rather than a copy, so it is instant and costs no
extra disk whatever the size of the site. Files are moved aside rather than
deleted. If the import fails half way, or the result is not what you expected,
one button puts the site back as it was.

= A clean migration, if you want one =

A site that has been running for years carries expired transients, orphaned
metadata, spam, revisions and tables belonging to plugins that were removed long
ago. The import can leave all of that behind, so the new site starts smaller and
faster than the old one.

It is opt-in and folded away, because a migration is not the moment to surprise
anyone. Every option says what it will remove before it removes it, and the
restore point above is taken either way. The same options are available on a
direct site-to-site transfer.

= It refuses rather than breaking things =

Before anything is written, Open Migration compares the PHP and WordPress
versions at both ends. A site exported from PHP 8.2 does not quietly half-import
onto PHP 7.2: the import stops, says what the mismatch is, and tells you how to
change the PHP version at your host. It also reports which URLs still need
changing after a move, with the number of rows each change would touch.

= What gets exported =

* The full database for this site's table prefix
* `wp-content` - themes, plugins, uploads, languages and anything else you have put there

Transient caches (`wp-content/cache`, `wp-content/upgrade`) and other plugins' backup folders are skipped, because restoring them is pointless and they are often larger than the site itself.

= What is not touched =

`wp-config.php`, WordPress core files and your database credentials are never included in the archive and never overwritten. The destination keeps its own configuration.

= Who makes this =

Open Migration is built and maintained by [DiceCodes](https://dicecodes.com/). It is free and open source under GPLv2, with no paid tier, no upsells and no data collection of any kind.

== Installation ==

1. In the WordPress admin, go to **Plugins - Add New** and search for **Open Migration Unlimited Site Transfer** - search the full name, because "open migration" on its own returns the larger migration plugins first. Click **Install Now**. To install manually instead, upload the plugin folder to `/wp-content/plugins/open-migration-unlimited-site-transfer-backup/`.
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

= How big a site can this handle? =

There is no fixed ceiling. Both the export and the import run in small batches, and each batch stops before the server's time limit and picks up where it left off. Multi-gigabyte sites work on ordinary shared hosting. The practical limit is disk space: the server needs room for the archive alongside the site itself, and the Export tab checks this before it starts.

= Does it work on shared hosting? =

Yes, and that is what it is designed for. It does not need shell access, WP-CLI, cron, or any raised PHP limit. If your host can run WordPress and has the ZipArchive extension, it works.

= How long does a migration take? =

Most small sites finish in a few minutes. Time scales with the number of files and the size of the database rather than with disk size alone, so a site with tens of thousands of small media files takes longer than a single large video. Progress is reported throughout, and closing the tab does not corrupt anything - the export or import resumes.

= Do I need to change any URLs after importing? =

No. The conversion runs automatically as part of the import and reports how many database rows it changed.

= Will my media library and page builder content survive? =

Yes. Media files are copied as they are, and the URL conversion rewrites references inside serialised option values and JSON-escaped block markup, which is where page builders such as Elementor, Divi and WPBakery keep their data. A plain database search and replace corrupts serialised values; this does not.

= Can I import a site that used a different table prefix? =

Yes. The prefix is recorded in the archive and converted during the restore, including the prefixed keys inside `options` and `usermeta`.

= Can I move a site from localhost to a live server, or the other way round? =

Yes, in both directions. Local addresses like `http://localhost/mysite` are handled the same as any other, including the port number if you use one.

= Can I use this to create a staging or test copy? =

Yes. Export the live site and import it onto a second install at a different address. The URL conversion means the copy works immediately at its own address without any manual editing.

= Why am I logged out at the end of an import? =

Restoring the database replaces the destination's user accounts with the ones from the archive, which invalidates your current session. Log back in with the username and password from the **original** site. If your account exists on both sites with administrator rights, the plugin signs you back in automatically.

= I imported on my host's temporary domain. What happens when DNS points to the real one? =

Nothing changes by itself - the database still contains the temporary address, so WordPress redirects visitors back to it. Once the real domain loads the new server, open the **Change Domain** tab, enter the real address and run it. That rewrites everything in one pass, including serialised and JSON-escaped references.

Do it in that order. Changing the address before DNS resolves will leave the site unreachable at both addresses.

= Can I change the domain again later, or move from http to https? =

Yes. The **Change Domain** tab works at any time and is not tied to an import.

= What happens if an import fails partway through? =

The destination is left with whatever had been restored up to that point, so start the import again rather than leaving it. The **Abort** button cleans up the working files and the session key. Because a failed import can leave a site in a half-restored state, take a backup of the destination first if it holds anything you care about.

= Do I need to deactivate my other plugins first? =

No. The archive contains your plugins as they were, and they come back in the same state on the destination.

= Does this work on multisite? =

Not yet. Multisite networks are detected and refused rather than partially migrated.

= What are the server requirements? =

PHP 7.2 or newer with the ZipArchive extension enabled, and enough free disk space for the archive. The Export tab shows a server check before you start.

= Where are backups stored, and can anyone download them? =

In `wp-content/uploads/open-migration-unlimited-site-transfer-backup/`. That directory carries deny-all rules for Apache and IIS and an index file, but those rules do nothing on Nginx, which ignores `.htaccess` entirely. So nothing in there relies on them: every file that would matter to anybody else - the archives, the rollback record, the recovery details, and the folder themes and plugins are moved into during a clean install - is named with 32 random characters, which no request can guess and no directory listing will hand out. Downloads are streamed through WordPress only after a capability and nonce check.

= Does the plugin connect to any external service? =

Only when you use the Site-to-Site tab, and only to the address you supply. Generating a token makes one request to your own site's REST API to confirm it is reachable. Pulling a site makes a request to the source site whose token you pasted. No data is sent to the plugin author or any third party, and no analytics or tracking of any kind is included.

= How does the plugin keep my site safe? =

Every administrative action checks a nonce and the `manage_options` capability. Archive entries are validated one by one before extraction, so a crafted `.zip` cannot write outside `wp-content`. Table names are validated before use and every value is bound through `$wpdb->prepare()`. Backups are streamed through WordPress after a permission check rather than being served as directly reachable files, and the address you enter in Change Domain is fully validated rather than silently corrected.

The only thing written outside `wp-content/uploads/open-migration-unlimited-site-transfer-backup/` is whatever an archive you chose to restore actually contains. Nothing is written to the plugin directory or to `mu-plugins`.

= Is it really free? =

Yes. There is no paid tier, no feature held back, no upsell, and no size limit. It is GPLv2 open source.
= Can I undo an import? =

Yes. A restore point is taken before the import begins, and it is offered back
from the error screen if something fails or from the plugin's screen afterwards
if the result is not what you wanted. The database side is a table rename, so it
is instant and uses no extra disk; files are moved aside rather than deleted.

= Can I leave the old clutter behind when I move? =

Yes, and it is opt-in. The import can skip expired transients, orphaned
metadata, spam, post revisions and tables left behind by plugins that are no
longer installed, so the new site is smaller than the old one. Each option says
what it removes before it removes it, and the restore point is taken whether or
not you use any of them.

= What happens if the new host runs a different PHP version? =

The import stops before writing anything and tells you. Open Migration compares
the PHP and WordPress versions at both ends first, and a mismatch that would
break the site is a refusal with the steps to change the PHP version at your
host — not a half-finished import you discover later.

== Screenshots ==

1. Export tab with the server check and progress reporting.
2. Import tab with drag-and-drop upload.
3. Direct site-to-site transfer using a one-time token.
4. Changing the site domain after DNS is pointed at the new server.
5. Managing existing backups.

== Changelog ==

= 1.6.10 =

* The recovery note has one fallback, used when the file holding this site's exact commands cannot be written -- a full disk, usually, which is also a common way an import dies in the first place. That fallback copied the table names, the prefix, the SQL and the folder path into the note itself, which is the one file in there anybody can fetch: in the one situation where somebody is already having a bad day, it undid the whole of 1.6.9. It now explains both steps without naming anything specific to the site. Nothing is lost by that, because what it needs to say is already public either way: the prefix it tells you to look for is a fixed string in this plugin's source, and anybody at a phpMyAdmin window or an FTP listing can see which tables and which folder it points at on their own site. The shorter note is also likelier to be written at all, which is the point when the disk is what failed.

= 1.6.9 =

Addresses everything raised in the Plugins Team review of 17 November 2026.

* Nothing inside the plugin's folder under uploads is named in a way that can be worked out from the outside any more. That folder is served by the web server like any other, and the deny rules written into it only work on Apache and IIS — Nginx ignores them entirely. Incoming archives already understood this and carried 32 random characters in their names; three other things did not. The rollback record, which lists the site's database tables, and the folder that themes and plugins are moved into during a clean install — whole directories of runnable PHP, previously laid out under a path anybody could predict from a plugin's name — are now named the same way. The name is written down once and then looked up, rather than recalculated, so nothing the site does later can rename a folder this plugin still needs to find.
* The "READ ME IF THE SITE IS BROKEN" file keeps its plain name, because somebody locked out of the dashboard has to be able to find it. It no longer contains anything about this particular site. The table names, the SQL to paste into phpMyAdmin and the path the folders were moved to are in a second file beside it, whose name cannot be guessed. That name is not printed in the first file: the first file is the one a stranger can fetch, so naming it there would hand it over and the unguessable name would be worth nothing. It says instead that the second file is there and what it looks like. Over FTP or a file manager both are simply in the listing, which is where they were always meant to be read, and a web request cannot list a folder.
* Anything parked under the old folder name is carried across the first time the new version looks, so an update landing in the middle of a restore cannot strand the only copy of somebody's themes and plugins.
* Execution time is raised for the steps that need it and no longer removed altogether. It used to be set to "no limit" for every step, including a two-megabyte upload chunk — an ordinary short write that any default covers many times over. A host that caps execution time means it, and the work here is batched anyway: a batch hands control back to the browser on its own after fifteen seconds, so the ceiling was never what kept a long export alive.

= 1.6.8 =

* While a job runs, the progress now takes the middle of the screen and the rest of the page is held: an export or an import is a chain of steps driven from the browser, and wandering off part way through is how a site ends up half-restored. It covers every job — export, import, site-to-site transfer, changing the address, and putting a site back from a restore point, which previously showed nothing but a greyed-out button. The panel closes itself when the job ends, and a job that fails grows a Close button so nobody is ever left on a screen they cannot leave.
* Leaving or reloading the page during one of those now warns first, and the panel says what stopping part way through would cost. Browsers replaced custom wording in that dialog with a generic line years ago, so the specific consequence is said on the page instead.
Older releases are listed in changelog.txt, which ships with the plugin.

== Upgrade Notice ==

= 1.5.3 =
The link check added in 1.5.2 did not work and reported sites as clean when they were not. Update if you relied on it.

= 1.5.2 =
Fixes unreadable admin notices from other plugins, and makes the Verify tab able to spot links to an old site even when an import crashed.

= 1.5.1 =
Security fix. 1.5.0 could expose the WordPress installer to anyone while an import was running. Update before importing again.

= 1.5.0 =
Adds a one-click undo for a failed import, an on-disk recovery guide, and a Verify tab for checking a site after a migration.

= 1.4.1 =
Recommended. An import can no longer leave the destination unable to load.

= 1.4.0 =
Adds an optional clean install mode and runs the WordPress database upgrade for you when importing from an older version.

= 1.3.16 =
Important. Fixes an import that could leave the destination site showing a critical error on every page. Update before your next migration.

= 1.3.15 =
Fixes percent signs being replaced by a random token after a migration, and backup downloads failing as an expired link. Re-export any archive made with an earlier version.

= 1.3.12 =
Fixes a bug that produced a backup with no media on sites that keep their uploads folder outside wp-content. Recommended for anyone on such a setup.

= 1.3.0 =
Adds a Change Domain tool with a DNS safety check, hardens how the import session key is stored, and fixes a memory problem when exporting sites with very large posts.

= 1.2.0 =
Important security and correctness release. Fixes two file-write vulnerabilities in the import routine, stops exports from absorbing previous backups, and adds automatic table prefix conversion. Upgrading is strongly recommended.
