# Slice Backup – Backup, Restore & Migration...

[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)

**Contributors:** wpengineers  
**Tags:** backup, migration, restore, cloud backup, encryption  
**Requires WordPress:** 6.2+  
**Tested up to:** 7.1  
**Requires PHP:** 7.4+  
**Stable version:** 1.0.0-beta  
**License:** GPLv2 or later

Back up, move, and restore WordPress sites with a complete, resumable backup and migration solution. Encrypt your backups, schedule automatic jobs, send them to your own cloud storage, or transfer sites directly between servers.

---

## Description

**Slice Backup** packs your WordPress site into a single backup bundle that you can keep locally, send to your own cloud storage, or restore on another server.

All features are built in and available for free. There are:

- No paid add-ons
- No license keys
- No proprietary storage
- No storage resale
- No dependency on a Slice Backup cloud account

**You bring your own storage account.**

---

## Key Features

### 🔄 Resumable Backups & Restores

Backups and restores run as background jobs made up of short processing steps, with **20 seconds per step by default**.

Each step saves its current position, allowing the next step to continue from where the previous one stopped.

Jobs can be driven by:

- The open WordPress admin page
- WordPress loopback requests
- WP-Cron

This means:

- Host and proxy execution limits are less likely to interrupt large operations.
- Closing the browser does not cancel a running job.
- Interrupted steps resume instead of restarting from the beginning.
- Large WordPress sites can be processed without requiring one long-running PHP request.

---

### 🔐 AES-256 Encryption

Slice Backup supports strong encryption for backup bundles.

- Bundles can be encrypted using **AES-256-GCM**.
- The encryption key is derived from your passphrase using **PBKDF2-SHA256 with 310,000 iterations**.
- Every **1 MB chunk** is authenticated, allowing modifications, reordering, or truncation of the backup to be detected before restoration.
- Bundle metadata, including source-site and content information, is encrypted as well.
- Encrypted metadata can only be viewed after entering the correct passphrase.
- Saved passphrases and cloud secret keys are encrypted using your site's WordPress security keys and are never stored in plain text.
- The encryption passphrase can alternatively be defined using `SLICE_BACKUP_ENCRYPTION_PASSPHRASE` in `wp-config.php`.

> **Important:** If an encrypted backup's passphrase is lost, the backup cannot be decrypted.

---

### ☁️ Your Own Cloud Storage

Slice Backup works with your own storage accounts rather than requiring proprietary cloud storage.

#### S3-Compatible Storage

Supported services include:

- Amazon S3
- Cloudflare R2
- Google Cloud Storage using HMAC keys
- Wasabi
- Backblaze B2
- DigitalOcean Spaces
- MinIO
- Other S3-compatible servers

#### Other Storage Options

- **SFTP / SSH servers**
  - Password authentication
  - Private-key authentication
  - OpenSSH keys
  - PEM keys
  - Passphrase-protected keys
- **Dropbox**
  - Your own Dropbox application
  - Files stored in the application's App folder

The settings screen provides links to provider documentation, signup pages, pricing information, and key-creation instructions.

#### Connection Testing

The **Test Connection** feature performs an actual storage operation by:

1. Writing a test file.
2. Listing the storage location.
3. Deleting the test file.

If something fails, Slice Backup reports the provider's error where available, such as:

- Invalid credentials
- Missing bucket
- Incorrect region
- Missing permissions

Large bundles are uploaded in parts and can resume after interruptions.

Bundles can also be downloaded back to the WordPress server and restored.

#### SFTP Security

SFTP connections pin the server's **host-key fingerprint** during the first connection.

If the fingerprint changes later, Slice Backup refuses the connection. This helps protect against man-in-the-middle attacks.

#### Dropbox Security

Dropbox authentication uses **OAuth 2 PKCE** with the App Key of a Dropbox application created in your own account.

- No app secret is required.
- Only an encrypted refresh token is stored.
- Setup instructions are provided directly on the Slice Backup settings screen.

---

### 🔁 Site-to-Site Transfer

Move a WordPress site directly from one server to another without first downloading the backup to your computer.

#### Push

1. On the receiving site, create a transfer key under **Settings → Site-to-site Transfer**.
2. Choose a validity period between **1 hour and 7 days**.
3. On the source site, enable **Send directly to another site**.
4. Enter the transfer key.
5. Optionally restore the backup automatically on the destination site.

#### Pull

On the destination site:

1. Go to **Restore → From another site**.
2. Enter the source site's transfer key.
3. Start the transfer.

#### Transfer Security

Every site-to-site request is authenticated using **HMAC-SHA256** over:

- HTTP method
- Request route
- Request body
- Timestamp
- Single-use nonce

Old, replayed, modified, or unsigned requests are rejected.

Transfer keys can be revoked at any time.

---

### ⏰ Scheduled Backups

Automate backups on a recurring schedule.

Supported intervals:

- Every 12 hours
- Daily
- Weekly
- Monthly

Schedules can run at a chosen time according to the site's configured timezone.

Additional options include:

- Optional encryption
- Automatic cloud upload
- Optional removal of the server-side copy after upload
- Email notifications after every backup or only when a backup fails
- Optional JSON webhook notifications
- **Run scheduled backup now** for testing

#### Grandfather-Father-Son Retention

Slice Backup supports GFS-style retention.

You can retain the newest backup for each of the last:

- **N days**
- **N weeks**
- **N months**

Retention can apply to both local and cloud backups.

> Manual backups are never removed by the scheduled-retention rule.

Webhooks can be used with services such as:

- Slack
- Discord
- Zapier
- Other services capable of receiving JSON POST requests

---

## Migration & Restore

Slice Backup provides flexible restore options for different migration scenarios.

### Backup Components

Choose from:

- Full site
- Full site + WordPress core
- Database only
- Media only
- Custom combinations of site components

A live size estimate is provided before starting the operation.

### Large Browser Uploads

Backup bundles of any size can be uploaded through the browser in smaller pieces.

Uploads are:

- Chunked
- Resumable
- Designed to survive interrupted requests

### Serialization-Safe URL Replacement

During migration, Slice Backup performs URL replacement across text columns while preserving serialized data structures.

It:

- Does **not** call `unserialize()`.
- Handles JSON-escaped URLs.
- Handles both `http://` and `https://` variants.
- Processes text columns across the database.

### Database Table Prefix

Differences in WordPress database table prefixes are automatically converted during restoration.

### PHP Compatibility Protection

Slice Backup checks the destination site's PHP compatibility before restoring active plugins and themes.

If a plugin is identified as incompatible with the destination PHP version:

- The plugin is deactivated.
- Its files are retained.
- The affected plugin is reported.

If the active theme is identified as incompatible:

- It is replaced with a default WordPress theme.
- The change is reported.

This helps prevent a migration from leaving the destination site completely inaccessible because of a PHP compatibility problem.

### Multisite

WordPress Multisite installations can be restored as:

- An entire network
- A single site converted into a standalone WordPress installation

---

## WP-CLI

Slice Backup provides WP-CLI commands for automation and server-side workflows.

```bash
wp slice-backup export [--encrypt] [--upload] [--push=<key> --push-restore]

wp slice-backup import <file>

wp slice-backup pull <key>

wp slice-backup list

wp slice-backup delete

wp slice-backup cloud-test
```

---

## REST API

Slice Backup provides a REST API under:

```text
/wp-json/slice-backup/v1/
```

The API can be used for automation with WordPress **Application Passwords**.

---

## Security

Security is built into the backup, restore, storage, and transfer workflows.

### Access Control

- Only administrators can use Slice Backup.
- On WordPress Multisite installations, only **Super Administrators** can use the plugin.
- Every request is checked for the required capability.
- Nonces are verified for applicable requests.
- Background processing steps are authorized using a random per-job token.

### Backup File Protection

Backup bundles receive long, random filenames.

The temporary backup directory is blocked from direct web access on supported Apache and IIS configurations.

The **System** tab tests whether backup bundles can be downloaded directly from the web and provides the configuration rule required for environments such as Nginx.

### Archive Security

Archives are checked for **path traversal / Zip Slip** attacks before extraction.

`wp-config.php` contained inside a backup bundle is **never applied during restoration**.

### Webhook Security

Webhook requests are sent using WordPress's `wp_safe_remote_post()` function, which prevents requests to internal addresses.

---

## Installation

### Standard Installation

1. Download or clone the plugin.
2. Upload the `slice-backup` folder to:

   ```text
   /wp-content/plugins/
   ```

   Alternatively, install the ZIP file through **Plugins → Add New → Upload Plugin**.

3. Activate the plugin.
   - On Multisite, network-activate the plugin.
4. Open:

   **Tools → Slice Backup**

   On Multisite:

   **Network Admin → Slice Backup**

5. Optionally open **Settings** to:
   - Connect cloud storage
   - Configure an encryption passphrase
   - Set up scheduled backups
   - Configure notifications

### Moving a WordPress Site

To migrate a site:

1. Create a backup on the existing site.
2. Install WordPress on the destination server.
3. Install and activate Slice Backup on the destination.
4. Upload the backup bundle or select it from configured cloud storage.
5. Restore the backup.

---

## Frequently Asked Questions

### Do I need to pay for anything?

No. Slice Backup is free and includes all of its features.

Cloud storage is billed separately by the storage provider under **your own account**. The settings screen provides links to the relevant provider pricing pages.

### What happens if I lose the encryption passphrase?

An encrypted backup cannot be opened without its passphrase.

**Keep your encryption passphrase somewhere safe.**

Slice Backup cannot recover a lost encryption passphrase.

### Do scheduled backups run if nobody visits the site?

Scheduled backups use **WP-Cron**, which is normally triggered by site activity.

For low-traffic websites, configure a server cron job to call `wp-cron.php`, for example every 5 minutes.

The **System** tab warns when a scheduled backup is late.

### Where are backups stored?

Temporary backup files are stored in:

```text
wp-content/uploads/slice-backup-temp/
```

If cloud storage is configured, copies are also stored in your configured cloud bucket or storage account.

### Will I be logged out after a restore?

Usually, yes.

A database restore replaces the site's users and authentication data, so existing sessions may become invalid.

After the restore completes, log in using an account from the restored site.

The restore process itself continues in the background.

### What happens to my backups when I delete the plugin?

When the plugin is uninstalled:

- Settings are removed.
- Scheduled tasks are removed.
- Temporary files are removed.
- Backup bundles are retained by default.
- Cloud backups are **never deleted automatically** during uninstall.

To automatically delete local backup bundles during uninstall, define:

```php
define( 'SLICE_BACKUP_DELETE_BUNDLES_ON_UNINSTALL', true );
```

in `wp-config.php`.

### Does the plugin send data anywhere?

Only to destinations that you explicitly configure.

Depending on your configuration, Slice Backup may send data to:

- Your own cloud storage account
- Your SFTP server
- Your Dropbox account
- Another WordPress site for which you have created a transfer key
- The email address you configure for notifications
- The webhook URL you configure

**Slice Backup does not send your backup data to the plugin authors.**

---

## Third-Party Libraries

Slice Backup includes the following third-party libraries:

- [phpseclib](https://github.com/phpseclib/phpseclib) **3.0.58** — MIT License  
  Used for SSH/SFTP functionality and located in `vendor/phpseclib`.

- [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding) **2.8.2** — MIT License  
  Required by phpseclib and located in `vendor/constant_time_encoding`.

Both libraries are loaded only when SFTP functionality is used.

If another plugin has already loaded phpseclib 3, Slice Backup uses the existing loaded copy.

---

## External Services

Each external service is contacted **only after you configure it**, using your own account or credentials.

### Cloud Storage

Supported services include:

- Amazon S3
- Cloudflare R2
- Google Cloud Storage
- Wasabi
- Backblaze B2
- DigitalOcean Spaces
- Custom S3-compatible endpoints

Depending on the operation, backup bundles may be:

- Uploaded to your bucket
- Listed from your bucket
- Downloaded from your bucket
- Deleted from your bucket

You are responsible for reviewing the applicable provider's terms and privacy policy.

### Dropbox

Slice Backup communicates with:

```text
api.dropboxapi.com
content.dropboxapi.com
```

Operations take place within the Dropbox application's configured App folder.

- [Dropbox Terms](https://www.dropbox.com/terms)
- [Dropbox Privacy Policy](https://www.dropbox.com/privacy)

### SFTP

Slice Backup communicates with the SFTP server that you configure.

### Site-to-Site Transfer

Slice Backup communicates with another WordPress site only when you provide a valid transfer key.

### Webhooks

Slice Backup sends a JSON summary of scheduled backup operations to the webhook URL you configure.

---

## Hooks & Filters

Slice Backup provides the following WordPress hooks.

### Filters

```text
slice_backup_required_capability
```

Controls the capability required to use the plugin.

```text
slice_backup_chunk_size
```

Controls the number of database rows read per query.

```text
slice_backup_export_exclude_paths
```

Controls absolute paths excluded from a component during export.

```text
slice_backup_peer_sslverify
```

Controls TLS certificate verification for site-to-site requests.

Default:

```text
true
```

### Actions

```text
slice_backup_before_export
slice_backup_after_export
slice_backup_before_restore
slice_backup_after_restore
```

---

## Changelog

### 1.0.0-beta

**First public beta release.**

Includes:

- Resumable backups and restores
- AES-256-GCM encryption
- S3-compatible cloud storage
- SFTP support
- Dropbox support
- Site-to-site push and pull
- Scheduled backups
- Grandfather-Father-Son retention
- Serialization-safe URL replacement
- PHP compatibility checks
- WP-CLI integration
- REST API
- Multisite migration support
- Security-focused backup and transfer workflows

---

## License

Slice Backup is licensed under the **GNU General Public License v2.0 or later**.

See the [GPLv2 license](https://www.gnu.org/licenses/gpl-2.0.html) for details.
