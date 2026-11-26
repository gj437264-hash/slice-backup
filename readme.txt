=== Slice Backup – Backup, Restore & Migration ===
Contributors: gj437264
Tags: backup, migration, restore, cloud backup, encryption
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, move and restore WordPress sites in resumable steps, encrypted, scheduled, to your own cloud storage or another server.

== Description ==

**Slice Backup** packs your WordPress site into a single backup bundle that you can keep locally, send to your own cloud storage, or restore on another server.

All features are built in and available for free. There are:

* No paid add-ons
* No license keys
* No proprietary storage
* No storage resale
* No dependency on a Slice Backup cloud account

You bring your own storage account.

= Resumable backups & restores =

Backups and restores run as background jobs made up of short processing steps, with 20 seconds per step by default. Each step saves its current position, allowing the next step to continue from where the previous one stopped.

Jobs can be driven by:

* The open WordPress admin page
* WordPress loopback requests
* WP-Cron

This means host and proxy execution limits are less likely to interrupt large operations, closing the browser does not cancel a running job, interrupted steps resume instead of restarting from the beginning, and large WordPress sites can be processed without one long-running PHP request.

= AES-256 encryption =

* Bundles can be encrypted using AES-256-GCM.
* The encryption key is derived from your passphrase using PBKDF2-SHA256 with 310,000 iterations.
* Every 1 MB chunk is authenticated, allowing modifications, reordering, or truncation of the backup to be detected before restoration.
* Bundle metadata, including source-site and content information, is encrypted as well, and can only be viewed after entering the correct passphrase.
* Saved passphrases and cloud secret keys are encrypted using your site's WordPress security keys and are never stored in plain text.
* The encryption passphrase can alternatively be defined using `SLICE_BACKUP_ENCRYPTION_PASSPHRASE` in `wp-config.php`.

Important: if an encrypted backup's passphrase is lost, the backup cannot be decrypted.

= Your own cloud storage =

Slice Backup works with your own storage accounts rather than requiring proprietary cloud storage.

S3-compatible storage: Amazon S3, Cloudflare R2, Google Cloud Storage (HMAC keys), Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO, and other S3-compatible servers.

Other storage options:

* SFTP / SSH servers — password authentication, private-key authentication, OpenSSH keys, PEM keys, passphrase-protected keys.
* Dropbox — your own Dropbox application, with files stored in the application's App folder.

The settings screen provides links to provider documentation, signup pages, pricing information, and key-creation instructions.

The "Test Connection" feature performs an actual storage operation (writing a test file, listing the storage location, then deleting the test file) and reports the provider's error where available. Large bundles are uploaded in parts and can resume after interruptions; bundles can also be downloaded back to the WordPress server and restored.

SFTP connections pin the server's host-key fingerprint during the first connection; if the fingerprint changes later, Slice Backup refuses the connection. Dropbox authentication uses OAuth 2 PKCE with the App Key of a Dropbox application created in your own account — no app secret is required, and only an encrypted refresh token is stored.

= Site-to-site transfer =

Move a WordPress site directly from one server to another without first downloading the backup to your computer.

Push: on the receiving site, create a transfer key under Settings > Site-to-site Transfer, choose a validity period between 1 hour and 7 days; on the source site, enable "Send directly to another site", enter the transfer key, and optionally restore the backup automatically on the destination site.

Pull: on the destination site, go to Restore > From another site, enter the source site's transfer key, and start the transfer.

Every site-to-site request is authenticated using HMAC-SHA256 over the HTTP method, request route, request body, timestamp and a single-use nonce. Old, replayed, modified, or unsigned requests are rejected, and transfer keys can be revoked at any time.

= Scheduled backups =

Automate backups on a recurring schedule: every 12 hours, daily, weekly, or monthly, at a chosen time according to the site's configured timezone.

Additional options include optional encryption, automatic cloud upload, optional removal of the server-side copy after upload, email notifications after every backup or only when a backup fails, optional JSON webhook notifications, and "Run scheduled backup now" for testing.

Grandfather-Father-Son retention: keep the newest backup for each of the last N days, N weeks, and N months, applied to both local and cloud backups. Manual backups are never removed by the scheduled-retention rule.

Webhooks can be used with services such as Slack, Discord, Zapier, or any other service capable of receiving JSON POST requests.

= Migration & restore =

Choose from full site, full site + WordPress core, database only, media only, or custom combinations of site components. A live size estimate is provided before starting the operation.

Backup bundles of any size can be uploaded through the browser in smaller, chunked, resumable pieces designed to survive interrupted requests.

During migration, Slice Backup performs URL replacement across text columns while preserving serialized data structures: it does not call `unserialize()`, handles JSON-escaped URLs, handles both `http://` and `https://` variants, and processes text columns across the database. Differences in WordPress database table prefixes are automatically converted during restoration.

Slice Backup checks the destination site's PHP compatibility before restoring active plugins and themes. An incompatible plugin is deactivated (its files are retained) and reported; an incompatible active theme is replaced with a default WordPress theme and the change is reported. This helps prevent a migration from leaving the destination site completely inaccessible because of a PHP compatibility problem.

WordPress Multisite installations can be restored as an entire network, or as a single site converted into a standalone WordPress installation.

= WP-CLI =

`wp slice-backup export [--encrypt] [--upload] [--push=<key> --push-restore]`
`wp slice-backup import <file>`
`wp slice-backup pull <key>`
`wp slice-backup list`
`wp slice-backup delete`
`wp slice-backup cloud-test`

= REST API =

Slice Backup provides a REST API under `/wp-json/slice-backup/v1/`, usable for automation with WordPress Application Passwords.

= Security =

Only administrators can use Slice Backup; on WordPress Multisite installations, only Super Administrators can use the plugin. Every request is checked for the required capability, nonces are verified for applicable requests, and background processing steps are authorized using a random per-job token.

Backup bundles receive long, random filenames. The temporary backup directory is blocked from direct web access on supported Apache and IIS configurations, and the System tab tests whether backup bundles can be downloaded directly from the web and provides the configuration rule required for environments such as Nginx.

Archives are checked for path traversal / Zip Slip attacks before extraction. Authentication keys, salts, the cookie hash and the database password are redacted from the wp-config.php copy stored in a bundle, whether they are declared with `define()` or `const` and whatever the value is (a quoted string, a call such as `getenv()`, or any other expression); the redaction uses PHP's own tokenizer, so a bracket or semicolon inside a key cannot leave part of it behind. wp-config.php contained inside a backup bundle is never applied during restoration.

The site's authentication keys and salts are themselves only ever used on the site: `wp_salt()` derives the local key that encrypts saved passphrases and cloud secrets, and that derived key is never written to a bundle, logged or transmitted.

While a restore is running, Slice Backup adds one temporary must-use plugin, `0-slice-backup-restore-guard.php`. A restore replaces the database and the plugin and theme files over many requests, and that file is what stops a half-copied or PHP-incompatible plugin from taking the site down while it happens: during the restore, a request loads only Slice Backup and no theme code, and visitors get a "back in a few minutes" page. It chooses which plugins a request loads and stops doing so at `plugins_loaded`, so activating or deactivating a plugin yourself during a restore still reads and writes your site's real list — a restore never changes which plugins are active. WordPress loads must-use plugins from `WPMU_PLUGIN_DIR` only, so there is no other location this file could work from. It is removed as soon as the restore ends, removes itself if the restore stops making progress, and is deleted when the plugin is uninstalled.

Everything else Slice Backup writes goes to its own folder inside `wp_upload_dir()` — bundles, each job's working files, the restore guard's settings and the restore report — or to the database, and that folder denies direct web access. A restore also needs two hidden folders beside `wp-content/plugins` and `wp-content/themes`, because the new folders are swapped in with a single rename and the folders they replace are kept there as the safety copy; the safety copy's folder is given a random name and the same deny rules, and is removed when the safety copy is dropped.

Webhook requests are sent using WordPress's `wp_safe_remote_post()` function, which prevents requests to internal addresses.

== Installation ==

1. Download or clone the plugin, then upload the `slice-backup` folder to `/wp-content/plugins/`, or install the ZIP file through Plugins > Add New > Upload Plugin.
2. Activate the plugin (on Multisite, network-activate the plugin).
3. Open Tools > Slice Backup (on Multisite: Network Admin > Slice Backup).
4. Optionally open Settings to connect cloud storage, configure an encryption passphrase, set up scheduled backups, and configure notifications.

To migrate a site: create a backup on the existing site, install WordPress on the destination server, install and activate Slice Backup on the destination, upload the backup bundle or select it from configured cloud storage, then restore the backup.

== Frequently Asked Questions ==

= Do I need to pay for anything? =

No. Slice Backup is free and includes all of its features. Cloud storage is billed separately by the storage provider under your own account; the settings screen provides links to the relevant provider pricing pages.

= What happens if I lose the encryption passphrase? =

An encrypted backup cannot be opened without its passphrase. Keep your encryption passphrase somewhere safe — Slice Backup cannot recover a lost encryption passphrase.

= Do scheduled backups run if nobody visits the site? =

Scheduled backups use WP-Cron, which is normally triggered by site activity. For low-traffic websites, configure a server cron job to call `wp-cron.php`, for example every 5 minutes. The System tab warns when a scheduled backup is late.

= Where are backups stored? =

Temporary backup files are stored in `wp-content/uploads/slice-backup-temp/`. If cloud storage is configured, copies are also stored in your configured cloud bucket or storage account.

= Will I be logged out after a restore? =

Usually, yes. A database restore replaces the site's users and authentication data, so existing sessions may become invalid. After the restore completes, log in using an account from the restored site; the restore process itself continues in the background.

= What happens to my backups when I delete the plugin? =

Settings, scheduled tasks, and temporary files are removed. Backup bundles are retained by default, and cloud backups are never deleted automatically during uninstall. To automatically delete local backup bundles during uninstall, define `SLICE_BACKUP_DELETE_BUNDLES_ON_UNINSTALL` as `true` in `wp-config.php`.

= Does the plugin send data anywhere? =

Only to destinations that you explicitly configure or use: your own cloud storage account, your SFTP server, your Dropbox account, a share link you paste in, another WordPress site for which you have created a transfer key, the email address you configure for notifications, or the webhook URL you configure. WordPress.org is contacted in one case only: if a restore has to install a default theme because the restored theme is not compatible. Slice Backup does not send your backup data to the plugin authors. See "External services" below for details on each one.

== External services ==

Each external service below is contacted only after you configure or explicitly use it, with your own account, credentials, or a link you paste in. Slice Backup does not phone home and sends nothing to its own authors. No external service is contacted on a normal page load, on activation, or by a backup that is kept on the server.

**Cloud storage (S3-compatible).** Used to store and retrieve your backup bundles in a bucket you own. Contacted only when you have configured cloud storage and you run a backup, a restore, "Test Connection", or a retention cleanup. What is sent at that time: the bundle's bytes (upload or download), and the request metadata needed to address and sign the call — bucket name, object key, your access key ID, the AWS Signature V4 headers, and the object listing/delete requests retention needs. The host contacted is the endpoint of the provider you selected, shown in the settings screen: `s3.{region}.amazonaws.com`, `{account}.r2.cloudflarestorage.com`, `storage.googleapis.com`, `s3.{region}.wasabisys.com`, `s3.{region}.backblazeb2.com`, `{region}.digitaloceanspaces.com`, or the custom endpoint you enter yourself.

* Amazon S3 — [Terms](https://aws.amazon.com/agreement/), [Privacy](https://aws.amazon.com/privacy/)
* Cloudflare R2 — [Terms](https://www.cloudflare.com/service-specific-terms-application-services/), [Privacy](https://www.cloudflare.com/privacypolicy/)
* Google Cloud Storage (via HMAC keys) — [Terms](https://cloud.google.com/terms), [Privacy](https://policies.google.com/privacy)
* Wasabi — [Terms](https://wasabi.com/legal), [Privacy](https://wasabi.com/legal/privacy-policy)
* Backblaze B2 — [Terms](https://www.backblaze.com/company/terms.html), [Privacy](https://www.backblaze.com/company/privacy.html)
* DigitalOcean Spaces — [Terms](https://www.digitalocean.com/legal/terms-of-service-agreement), [Privacy](https://www.digitalocean.com/legal/privacy-policy)
* MinIO, or another custom S3-compatible endpoint you point the plugin at — this is infrastructure you (or your own host) operate or choose; review that provider's own terms and privacy policy. MinIO project: [min.io](https://min.io/)

**Dropbox** (storage destination, and importing a shared link). Contacted only when you connect a Dropbox account as a destination, run a backup, restore or "Test Connection" against it, or paste a Dropbox share link to import. Hosts contacted: `www.dropbox.com` (the OAuth 2 PKCE authorisation page you open yourself), `api.dropboxapi.com` and `content.dropboxapi.com`. What is sent: the bundle's bytes, the file name and folder path inside the app folder, and an OAuth 2 access token for the Dropbox app you created in your own account. Storage operations are confined to that app's App folder. [Dropbox Terms](https://www.dropbox.com/terms), [Dropbox Privacy Policy](https://www.dropbox.com/privacy).

**Google Drive and OneDrive/SharePoint** (importing a shared link). Used only by the "Import from a link" feature. Contacted only when you paste a public "share" link and start the import: Slice Backup sends an HTTP GET request (with a Range header, and no credentials) to the provider's file-content URL for that specific link, and receives the bundle's bytes. Hosts contacted: `drive.google.com`, `docs.google.com` and `drive.usercontent.google.com` for a Google Drive link; `1drv.ms`, `onedrive.live.com`, `api.onedrive.com` and your `*.sharepoint.com` host for a OneDrive or SharePoint link. Nothing else in your Google or Microsoft account is signed in to, browsed or accessed.

* Google Drive — [Terms](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy)
* OneDrive / SharePoint — [Microsoft Services Agreement](https://www.microsoft.com/en-us/servicesagreement), [Microsoft Privacy Statement](https://www.microsoft.com/en-us/privacy/privacystatement)

**SFTP.** Contacted only when you configure an SFTP destination and run a backup, restore or "Test Connection". Slice Backup connects to the host and port you enter and sends the user name and the password or private key you provide, then transfers the bundle's bytes. This is typically your own server or one operated by your own host; review its operator's terms and privacy policy.

**WordPress.org.** Contacted in one case: when a restore finds that the restored theme cannot run on this server's PHP version and no default theme is installed to fall back on, Slice Backup downloads one from `downloads.wordpress.org` (for example `https://downloads.wordpress.org/theme/twentytwentyfour.latest-stable.zip`), exactly as WordPress itself does when installing a theme. The request carries no site data beyond what any HTTP request includes. [WordPress.org Privacy Policy](https://wordpress.org/about/privacy/)

**Site-to-site transfer.** Contacted only when you create or enter a valid transfer key. Slice Backup then sends (or receives) the backup bundle directly between the two WordPress sites you control, over the REST API of the other site, with each request signed using HMAC-SHA256. The host contacted is the address of the other site, which you supply.

**Webhooks.** Contacted only when you configure a webhook URL. After each scheduled backup, Slice Backup sends a JSON summary to that URL via an HTTP POST: whether the backup succeeded or failed, your site's URL, the bundle's file name and size, the job's identifier, and any error or warning text. No backup content is sent. Since the URL is one you supply, review that destination's own terms and privacy policy.

**Email notifications.** Sent through WordPress's own `wp_mail()` to the address you configure, so they go out through your site's existing mail setup, not through any service of ours.

== Third-party libraries ==

Slice Backup bundles two libraries, both under the MIT license, which is GPL-compatible. Their unmodified source is included in the plugin, and each folder keeps the library's own license file.

* phpseclib 3.0.58 (`vendor/phpseclib`, [source](https://github.com/phpseclib/phpseclib), MIT) — the SSH and SFTP implementation used by the SFTP destination. If another plugin has already loaded phpseclib 3, Slice Backup uses that copy instead of its own.
* paragonie/constant_time_encoding 2.8.2 (`vendor/constant_time_encoding`, [source](https://github.com/paragonie/constant_time_encoding), MIT) — required by phpseclib.

Nothing else is bundled: everything WordPress already ships is used from WordPress.

== Hooks & Filters ==

Filters: `slice_backup_required_capability`, `slice_backup_chunk_size`, `slice_backup_export_exclude_paths`, `slice_backup_peer_sslverify` (default true).

Actions: `slice_backup_before_export`, `slice_backup_after_export`, `slice_backup_before_restore`, `slice_backup_after_restore`.

== Changelog ==

= 1.1.1 =
* The wp-config.php copy kept in a bundle now has its authentication keys, salts, cookie hash and database password removed however they are declared — `define()` or `const`, and with any kind of value, including `getenv()` and other expressions. The redaction uses PHP's own tokenizer.
* The redacted copy is written as a work file with a random name and a .txt extension, instead of a loose .php file.
* The restore guard no longer keeps its settings in the must-use plugins folder; they live with the restore job's other working files under wp-content/uploads. Only the must-use plugin itself, which can work nowhere else, is still written there.
* The restore guard stops choosing which plugins load at `plugins_loaded`, so activating or deactivating a plugin while a restore runs always reads and writes the site's real list. A restore can no longer switch other plugins off by saving a masked list back.
* The folder holding a restore's safety copy now gets a random name and deny-all rules, so replaced plugin and theme code is not left at a path that can be requested.
* The safety-copy record moved from a JSON file in the backup folder into the database.
* Bundles taken in from a browser upload, a share link, another site or WP-CLI are stored under an unguessable file name, like the ones this plugin creates itself.
* "Import from a link" now refuses a link that is not a public address and fetches with `wp_safe_remote_get()`, so neither a pasted link nor a redirect can make the site request a private or loopback address.
* Removed the placeholder translation template; translations are supplied by WordPress.org.
* readme: corrected the Wasabi and Microsoft policy links, documented every external service's hosts, the data sent, and the one case in which WordPress.org is contacted, and listed the bundled libraries and their licenses.

= 1.1.0 =
* Imports can now be fetched directly from a public Google Drive, OneDrive/SharePoint or Dropbox share link, without downloading the bundle locally first.
* Restores now stage the database and plugin/theme files and switch them in atomically, keeping a safety copy: a restore can be undone, or its safety copy discarded, from the Restore tab or `wp slice-backup rollback`.
* Conflicting plugins are deactivated and a conflicting theme falls back to an installed default theme (or downloads one) automatically, so a restore keeps going instead of leaving a broken site.
* A crashed step (out of memory, a host time limit) is now detected and retried in smaller pieces instead of repeating indefinitely; database export and search-replace batches are sized by memory rather than row count.
* Every restore now produces a detailed, downloadable report of what happened and any follow-up action needed.

= 1.0.0-beta =
First public beta release. Includes resumable backups and restores, AES-256-GCM encryption, S3-compatible cloud storage, SFTP support, Dropbox support, site-to-site push and pull, scheduled backups, Grandfather-Father-Son retention, serialization-safe URL replacement, PHP compatibility checks, WP-CLI integration, REST API, Multisite migration support, and security-focused backup and transfer workflows.

== Upgrade Notice ==

= 1.1.1 =
Security and file-location hardening: stronger wp-config.php redaction, unguessable private locations for a restore's safety copy and for imported bundles, and a restore guard that can no longer affect which plugins are active.

= 1.1.0 =
Restores now keep a safety copy and can be rolled back; see the Restore tab after upgrading.
