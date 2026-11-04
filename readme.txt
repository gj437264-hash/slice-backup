=== WP Bundle Migrator ===
Contributors: wpengineers
Tags: backup, migration, restore, cloud backup, encryption
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, move and restore WordPress sites. Encrypted, scheduled, sent to your own cloud storage, and resumable so large sites finish.

== Description ==

WP Bundle Migrator packs your site into a single bundle that you can keep as a backup, send to your own cloud storage, or restore on another server. All features are free and built in; there are no add-ons, licence keys or storage resale. You bring your own storage account.

= Backups that finish, even on large sites =

Backups and restores run as background jobs made of short steps (20 seconds by default). Each step saves its position, and the next step continues from there, driven by the open admin page, a loopback request, or WP-Cron. Host and proxy time limits no longer stop a big site, a closed browser tab does not cancel the job, and an interrupted step is resumed rather than restarted.

= AES-256 encryption =

* Bundles can be encrypted with AES-256-GCM. The key is derived from your passphrase with PBKDF2-SHA256 (310,000 iterations).
* Every 1 MB chunk is authenticated, so any modification, reordering or truncation of the file is detected before anything is restored.
* The bundle's details (source site, contents) are stored encrypted too and can be shown after entering the passphrase.
* A saved passphrase and cloud secret keys are stored encrypted with your site's security keys, never in plain text. The passphrase can instead be defined as `WPBM_ENCRYPTION_PASSPHRASE` in `wp-config.php`.

= Your own cloud storage =

Works with any S3-compatible service, using your own account and keys:

* Amazon S3
* Cloudflare R2
* Google Cloud Storage (HMAC keys)
* Wasabi
* Backblaze B2
* DigitalOcean Spaces
* MinIO and other S3-compatible servers

* SFTP / SSH servers (password or private key; OpenSSH and PEM keys, with passphrase)
* Dropbox (your own Dropbox app; files go to its App folder)

The settings screen links to each provider's sign-up, pricing and key-creation pages. "Test connection" really writes, lists and deletes a test file, and reports the provider's error (wrong key, missing bucket, wrong region, missing permission) if anything fails. Large bundles are uploaded in parts and resume after interruptions; bundles can be downloaded back to the server and restored.

* **SFTP** pins the server's host key fingerprint on the first connection and refuses to connect if it later changes (protection against man-in-the-middle attacks).
* **Dropbox** connects with OAuth 2 PKCE using the App key of an app you create in your own Dropbox account (step-by-step instructions on the settings screen). No app secret is needed; only an encrypted refresh token is stored.

= Site-to-site transfer (push and pull) =

Move a site straight from one server to another without downloading anything:

* On the receiving site, create a transfer key (valid 1 hour to 7 days) under Settings > Site-to-site transfer.
* Push: on the source site, tick "Send directly to another site", paste the key, and optionally restore it there automatically.
* Pull: on the destination site, choose Restore > From another site and paste the source site's key.
* Every request is signed with HMAC-SHA256 over the method, route, body, a timestamp and a single-use nonce. Old, replayed, tampered or unsigned requests are rejected, and keys can be revoked at any time.

= Scheduled backups =

* Every 12 hours, daily, weekly or monthly, at a chosen time in your site's time zone.
* Optional encryption and cloud upload, optionally removing the server copy afterwards.
* Grandfather-father-son retention: keep the newest backup of each of the last N days, weeks and months, on the server and in cloud storage. Manual backups are never removed by this rule.
* Email on failure or after every backup, and an optional webhook (JSON POST) for Slack, Discord, Zapier and similar.
* "Run scheduled backup now" to test the setup.

= Migration and restore =

* Full site, full site plus WordPress core, database only, media only, or any custom mix, with a live size estimate.
* Upload bundles of any size from the browser (sent in pieces, resumable).
* Serialization-safe URL replacement in every text column of every table, without calling `unserialize()`. JSON-escaped URLs and the http/https variant are handled.
* Table prefix differences are converted automatically.
* PHP compatibility check: plugins that would crash on the destination's PHP version are deactivated (files kept) and a crashing active theme is replaced by a default theme, with a report.
* Multisite: whole network, or one site converted to a standalone site.
* WP-CLI: `wp bundle export [--encrypt] [--upload] [--push=<key> --push-restore]`, `wp bundle import <file>`, `wp bundle pull <key>`, `wp bundle list`, `wp bundle delete`, `wp bundle cloud-test`.
* REST API under `/wp-json/wp-bundle-migrator/v1/` for automation with Application Passwords.

= Security =

* Only administrators can use the plugin; on multisite only Super Admins.
* Every request is checked for permission and a nonce. Background steps are authorized by a random per-job token.
* Bundles get long random names in a folder blocked from the web on Apache and IIS. The System tab tests whether bundles can be downloaded from the web (for example on Nginx) and shows the rule to add.
* Archives are checked for path traversal ("Zip Slip") before extraction. `wp-config.php` from a bundle is never applied.
* Webhooks are sent with `wp_safe_remote_post`, which refuses internal addresses.

== Installation ==

1. Upload the `wp-bundle-migrator` folder to `/wp-content/plugins/`, or install the zip under Plugins > Add New > Upload Plugin.
2. Activate the plugin (on multisite, network-activate it).
3. Open **Tools > Bundle Migrator** (on multisite: **Network Admin > Bundle Migrator**).
4. Optional: under **Settings**, connect cloud storage, save an encryption passphrase and set up a schedule.

To move a site: create a backup on the old site, install WordPress and this plugin on the new server, then restore the bundle there (upload it, or pick it from cloud storage).

== Frequently Asked Questions ==

= Do I need to pay for anything? =

No. The plugin is free and complete. Cloud storage is billed by your storage provider under your own account; the settings screen links to each provider's pricing page.

= What happens if I lose the encryption passphrase? =

Encrypted bundles cannot be opened without it, by anyone. Keep a copy somewhere safe.

= Do scheduled backups run if nobody visits the site? =

They run through WP-Cron, which is triggered by site visits. On low-traffic sites, add a server cron job that calls `wp-cron.php` every 5 minutes. The System tab warns when a scheduled backup is late.

= Where are backups stored? =

In `wp-content/uploads/wp-bundle-migrator-temp/`, and in your bucket if cloud upload is on.

= Will I be logged out after a restore? =

Usually yes, because restoring the database replaces all users. Log in with an account from the restored site. The restore itself keeps running in the background.

= What happens to my backups when I delete the plugin? =

Settings, schedules and temporary files are removed. Backup bundles are kept unless `WPBM_DELETE_BUNDLES_ON_UNINSTALL` is defined as true in `wp-config.php`. Cloud copies are never deleted on uninstall.

= Does the plugin send data anywhere? =

Only where you tell it to: your own cloud storage, SFTP server, Dropbox account, another site you hold a transfer key for, or the email address and webhook you configure. Nothing is sent to the plugin authors.

== Third-party libraries ==

* [phpseclib](https://github.com/phpseclib/phpseclib) 3.0.58 (MIT) - SSH/SFTP, in `vendor/phpseclib`.
* [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding) 2.8.2 (MIT) - required by phpseclib, in `vendor/constant_time_encoding`.

Both are loaded only when SFTP is used. If another plugin already loaded phpseclib 3, that copy is used.

== External services ==

Each service is contacted only after you configure it, using your own account:

* Amazon S3, Cloudflare R2, Google Cloud Storage, Wasabi, Backblaze B2, DigitalOcean Spaces or a custom S3 endpoint - bundles are uploaded to, listed in, downloaded from and deleted from the bucket you enter. See each provider's terms and privacy policy.
* Dropbox (api.dropboxapi.com, content.dropboxapi.com) - the same operations in your app's folder. [Terms](https://www.dropbox.com/terms), [privacy](https://www.dropbox.com/privacy).
* Your SFTP server.
* Another WordPress site you provide a transfer key for.
* The webhook URL you enter (a JSON summary of each scheduled backup).

== Hooks ==

* `wpbm_required_capability` (filter) - capability needed to use the plugin.
* `wpbm_chunk_size` (filter) - database rows read per query.
* `wpbm_export_exclude_paths` (filter) - absolute paths left out of a component.
* `wpbm_before_export`, `wpbm_after_export`, `wpbm_before_restore`, `wpbm_after_restore` (actions).

* `wpbm_peer_sslverify` (filter) - verify TLS certificates in site-to-site requests (default true).

== Changelog ==

= 1.3.0 =
* New: SFTP destination with password or private-key login and host key pinning (bundled phpseclib).
* New: Dropbox destination with OAuth 2 PKCE (your own app key), resumable upload sessions and ranged downloads.
* New: site-to-site push and pull with expiring, revocable transfer keys and HMAC-signed requests; optional automatic restore on the receiving site.
* New: WP-CLI `--push`, `--push-restore` and `wp bundle pull`.
* Changed: cloud storage code reorganized into pluggable destinations.

= 1.2.0 =
* New: resumable background jobs for backup, restore, cloud upload and cloud download. They survive host time limits, closed tabs and interrupted requests, and show live progress.
* New: AES-256-GCM bundle encryption with authenticated chunks and an encrypted manifest.
* New: cloud storage for Amazon S3, Cloudflare R2, Google Cloud Storage, Wasabi, Backblaze B2, DigitalOcean Spaces and MinIO, with a real connection test, multipart resumable uploads, cloud listing, download and delete.
* New: scheduled backups with grandfather-father-son retention (local and cloud), email notifications and webhooks.
* New: secrets (cloud keys, passphrase) are stored encrypted.
* New: WP-CLI `--encrypt`, `--upload`, `--passphrase` and `wp bundle cloud-test`.
* New: System checks for OpenSSL and WP-Cron.

= 1.1.0 =
* Security fixes (multisite privilege escalation, download handler, upload validation, storage protection) and many bug fixes; redesigned interface. Unimplemented features were removed.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
Adds SFTP, Dropbox and direct site-to-site transfer.

= 1.2.0 =
Adds encryption, cloud storage, scheduled backups and resumable background jobs.
