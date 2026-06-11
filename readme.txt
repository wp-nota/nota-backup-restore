=== Nota Backup & Restore ===
Contributors: wpnota
Tags: backup, restore, migration, database, clone
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WordPress backup plugin. Back up your entire site — files + database — with one click. Works on any shared hosting.

== Description ==

**Nota Backup & Restore** creates complete WordPress backups (all files + database) in a single ZIP file. Designed for reliability on shared hosting — chunked processing ensures backups never fail due to PHP timeouts or memory limits.

= Free Features =

* **One-click full backup** — all WordPress files + database in a single ZIP
* **Selective backup** — database only or files only
* **Chunked ZIP creation** — never times out, even on large sites
* **Configurable chunk settings** — files per chunk and MB per chunk
* **AES-256 encryption** for database backups
* **Custom exclusion rules** — skip any folder or file path
* **Backup history** with status, size, duration and error details
* **Estimated backup size** before you start
* **Dashboard widget** showing last backup status
* **Standalone installer** — migrate to a new domain without WordPress

= Premium Features (Pro Version) =

The following features require the [Pro version](https://www.wp-nota.com/pricing/):

* **Cloud Storage** — Google Drive, Amazon S3, Wasabi, Dropbox, Microsoft OneDrive, FTP/SFTP
* **Automatic scheduled backups** — daily, weekly, monthly
* **Admin panel restore** — one-click restore directly in WordPress
* **Emergency Recovery** — standalone restore page that works even when WordPress is broken
* **Email notifications** — success and failure alerts

= How It Works =

1. Go to **Nota Backup** in the WordPress admin sidebar
2. Click **Start Backup**
3. The plugin creates a ZIP file of all your files and database
4. Download the ZIP from the backup list

= Standalone Installer =

The installer is a standalone migration tool that runs without WordPress. It is downloaded separately from the admin panel — it is not bundled inside the backup ZIP:

1. Download the backup ZIP and the Installer PHP file from the backup list
2. Upload both files to the new server's web root
3. Open `https://newdomain.com/installer_{backup_name}.php`
4. The installer extracts the ZIP, then prompts for new database credentials and new site URL
5. Follow the step-by-step wizard — URLs and paths are replaced throughout the database

== Installation ==

1. Upload the `nota-backup-restore` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Nota Backup** in the WordPress admin sidebar
4. Create your first backup with one click

== Frequently Asked Questions ==

= Does it work on shared hosting? =
Yes. Nota Backup & Restore is built specifically for shared hosting. Chunked processing keeps every request well within PHP time limits and memory constraints.

= What is included in a full backup? =
All WordPress files (themes, plugins, uploads, wp-content) plus the complete MySQL database, packaged into a single ZIP file.

= Can I schedule automatic backups? =
Automatic scheduled backups require the Pro version. The free version supports on-demand (manual) backups.

= What happens if a backup fails halfway through? =
The plugin detects and cleans up incomplete backups automatically. Error details are logged to backup history.

= Is AES-256 encryption secure? =
Yes. The database is encrypted with AES-256-CBC. The encryption key is derived from your password using SHA-256.

= Can I restore to a different domain? =
Yes. The standalone installer handles full migrations. It replaces all URLs and paths — including inside serialized PHP data — so WordPress loads correctly on the new domain.

= How do I migrate WordPress to a new host? =
Download the backup ZIP and the Installer PHP file from the backup list. Upload both to the new server's web root, open the installer in your browser, enter the new database credentials and site URL, and the wizard handles the rest — including replacing all URLs in the database.

= How do I backup WordPress for free? =
Install Nota Backup & Restore, go to Nota Backup in the WordPress admin sidebar, and click Start Backup. No account or configuration required. Full site backups (files + database) are completely free.

= Does the free version connect to any external services? =
No. The free version makes no external API calls. The only external link is the "Upgrade to Pro" button, which opens `https://www.wp-nota.com/pricing/` in a new tab.

== External Services ==

This plugin does **not** connect to any external APIs or services. No data is transmitted to any third party. All backup processing (ZIP creation, database export, encryption) runs entirely on your own server.

The plugin displays a link to `https://www.wp-nota.com/pricing/` in the admin UI. This is a plain HTML hyperlink — clicking it opens the page in a new browser tab. No data of any kind is sent to this URL by the plugin.

Because no external service is used, no Terms of Use or Privacy Policy link is applicable to this plugin.

Note: The Pro version (distributed separately, not hosted on WordPress.org) connects to third-party cloud storage services (Google Drive, Amazon S3, Wasabi, Dropbox, Microsoft OneDrive, FTP/SFTP). That code is not present in this plugin.

== Screenshots ==

1. Main backup page — select backup type, see estimated size, and start a backup with one click.
2. Backup history — list of all backups with status, size, creation time, and download/actions buttons.
3. General Settings — configure chunk size, files per chunk, and AES-256 database encryption.
4. Exclusions — exclude cache directories, server config files, or any custom folder from backups.

== Changelog ==

= 2.1.8 =
* Security: Backup ZIP filenames now include a random suffix — site slug + timestamp alone could be brute-forced, and the ZIP contains wp-config.php plus the full database
* Security: Installer state and log files now use unguessable names derived from the backup archive name (DB credentials were stored in a fixed-name state file during migration)
* Security: Backup directory .htaccess now denies ALL direct HTTP access (downloads always go through authenticated admin-ajax); existing installs upgraded automatically
* Security: Admin warning when leftover installer/migration files (installer, SQL dump, state files, backup ZIP) are detected in the site root, with one-click safe deletion
* Security: Installer CSRF token no longer stored in a web-accessible file
* Improvement: Installer ZIP extraction is now chunked (~400 files / ~80 MB per request) with progress display — no more proxy timeouts on multi-GB sites
* Fix: Site Changes bar "WordPress updated" detection never worked — the wp_version column was missing from the backups table; added with automatic migration
* Fix: Site Changes bar counted deleted backups as the last backup — a deleted backup is not a restore point
* Fix: Leftover-files security notice could stay hidden for hours after a migration due to caching — now scans fresh on every admin page load
* Fix: History page "Register" button for orphan backups did nothing — its script was attached before the script handle was registered, so WordPress silently dropped it

= 2.1.7 =
* New: Real-time activity log panel during backup — shows DB export, file list, ZIP progress and completion in a terminal-style display
* Fix: Plugin Check warnings for non-sanitized JSON POST inputs (selected_paths, selected_tables) — sanitization happens correctly after json_decode

= 2.1.6 =
* New: Selective backup — Database Only and Files Only modes are now free (with table/folder picker)
* Fix: Checkbox visually not showing as checked in Settings > Exclusions
* Fix: Plugin language files now load correctly (load_textdomain with direct path)

= 2.1.5 =
* Fix: Backup log entries now show their real timestamps instead of all showing the finalize/cleanup time

= 2.1.4 =
* New: Review notice — admin banner shown 14 days after activation (plugin pages only) with remind/dismiss options
* New: Backup success review prompt — appears after each successful backup with one-click dismiss

= 2.1.3 =
* New: Settings > Exclusions — "Skip .ini files in WordPress root" option (default on); prevents php.ini / .user.ini from being included in backups and causing issues after migration
* New: Settings > Exclusions — Staging tab added with PRO badge
* Fix: Installer URL replace infinite loop on tables with non-UTF-8 primary keys (e.g. wp_wffilemods); cursor now base64-encoded in state
* Fix: Installer state save failure on non-UTF-8 database content (JSON_INVALID_UTF8_SUBSTITUTE)
* Fix: Installer MySQL error 1293 — TIMESTAMP multi-default incompatibility with MySQL 5.5 now auto-corrected
* Fix: Installer progress bar resetting on every replace chunk
* Fix: AJAX permission/nonce error showed generic "DB init failed." instead of actual message

= 2.1.2 =
* New: Site Changes Bar — shows what changed since the last backup (WordPress update, plugin/theme changes, new uploads) in the main backup card

= 2.1.1 =
* New: Activity Logs system — wpbn_logs DB table stores per-backup log entries (info/warning/error) for debugging and audit
* New: Logs admin page — backup rows are collapsible, showing timestamps and level badges; system-level logs shown separately
* New: Log retention setting — keep logs for last N backups (default 20), configurable from the Logs page

= 2.1.0 =
* New: Backup Encryption (AES-256) is now a free feature — password stored securely using WordPress secret keys
* New: Remove Encryption button in Settings to clear saved encryption password
* Fix: pollBackupStatus() now has a max retry limit (20) to prevent infinite polling on stale state
* Fix: ZIP close() failure recovery now rolls back offset to prevent double-processing files
* Cleanup: Removed dead and premium-only code from free plugin (restore engine stubs, cloud handlers, duplicate JS functions)

= 2.0.9 =
* New: French (fr_FR) translation added

= 2.0.8 =
* New: Cache directory exclusions moved to Settings — checkboxes for W3TC, WP Super Cache, WP Rocket, Divi, WP-Optimize, Breeze cache folders
* Improvement: Cache exclusion paths no longer hardcoded — stored in database, configurable per site

= 2.0.7 =
* Fix: Backup directory moved to uploads folder (`wp-content/uploads/nota-backup-restore`) per wp.org guidelines
* Fix: Removed PclZip fallback and global `PCLZIP_TEMPORARY_DIR` constant — ZipArchive is required

= 2.0.6 =
* Fix: Orphaned backup state (PHP killed mid-backup) now creates a failed record automatically via hourly cron — no longer requires opening the admin panel

= 2.0.5 =
* Fix: Removed non-functional notification code (email settings, test email handler) that was included but had no effect in the free version
* New: Backup type selector replaced with visual cards (Full, Database Only, Files Only)
* Improvement: File sizes in GB now display two decimal places (e.g. 1.34 GB)
* Improvement: ZIP chunk size is now auto-calculated based on available server memory
* Improvement: Files per chunk automatically scales with chunk size, with optional manual override in Settings

= 2.0.4 =
* Improvement: Smart chunk size auto-calculation based on server memory
* Improvement: Files per chunk auto-derived from chunk size (chunk MB × 40)

= 2.0.3 =
* Fix: Removed scheduled backup cron hook and callback — scheduled backups are a Pro feature and must not exist as locked code in the free version (Guideline 5)
* Fix: Removed `wpbn_run_cron_now` AJAX action that returned a premium-only error
* Fix: Removed cron schedule interval definitions (wpbn_daily, wpbn_weekly, etc.) which were only used for scheduled backups
* Fix: Removed `assets/icon-128x128.png` from plugin ZIP — plugin assets must be uploaded separately via SVN

= 2.0.2 =
* Fix: Estimated backup size now works — `wpbn_size_estimate` AJAX handler was missing and has been added
* Fix: DB health check and test email AJAX handlers were missing and have been added
* Security: `installer-template.php` removed from plugin; backup ZIPs no longer contain `installer.php` — the installer is now generated on demand and downloaded separately from the admin panel as a `.php` file, never written to the server filesystem

= 2.0.1 =
* Fix: Removed bundled-but-locked feature stubs to comply with WordPress.org Guideline 5 (Trialware)
* Fix: installer-template.php security hardening — removed error_reporting(E_ALL), capped max_execution_time to 3600, fixed CSRF token output escaping, sanitized HTTP_HOST
* New: Standalone installer is now downloaded separately alongside the backup ZIP — upload both to the new server, open installer.php, and it extracts the ZIP and migrates the database automatically

= 2.0.0 =
* New: Free version released on WordPress.org as "Nota Backup & Restore"
* Removed: Freemius SDK — no longer required in the free version
* Removed: Cloud storage code — available in Pro version only
* Removed: Restore engine — available in Pro version only
* Removed: Emergency Recovery — available in Pro version only

= 1.9.7 =
* New: "Files Per Chunk" setting — configures how many files are processed per ZIP request
* Fix: ZIP Chunk Size (MB) setting now correctly limits bytes per request during ZIP creation

= 1.9.6 =
* Fix: Plugin deactivation now clears scheduled cron events to prevent orphaned tasks
* Fix: Critical file write operations now return proper errors on failure instead of silently corrupting the backup

= 1.9.5 =
* Improvement: Database export now streams directly to disk in 500-row batches — prevents out-of-memory errors on large databases

= 1.4.3 =
* Fix: Bootstrap CSS now bundled locally instead of loaded from CDN
* Fix: Wrapped all admin_url() calls with esc_url()

= 1.0.0 =
* Initial release
