<?php
/**
 * WP-CLI commands. Recommended for large sites: no web server time limits apply.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Back up, migrate and restore sites with Slice Backup.
 */
final class CliCommand {
    /**
     * Creates a bundle.
     *
     * ## OPTIONS
     *
     * [--preset=<preset>]
     * : What to include.
     * ---
     * default: full
     * options:
     *   - full
     *   - turnkey_full
     *   - db_only
     *   - media_only
     * ---
     *
     * [--components=<list>]
     * : Comma-separated components, overrides --preset. Any of: db, uploads, plugins, themes, mu_plugins, languages, root_files, wp_core.
     *
     * [--subsite=<id>]
     * : Multisite only: export a single network site.
     *
     * [--standalone]
     * : With --subsite, convert the site into a standalone single-site bundle.
     *
     * [--exclude-spam]
     * : Skip spam and trashed comments.
     *
     * [--exclude-revisions]
     * : Skip post revisions and trashed posts.
     *
     * [--exclude-transients]
     * : Skip cached transients.
     *
     * [--encrypt]
     * : Encrypt the bundle with AES-256-GCM.
     *
     * [--passphrase=<passphrase>]
     * : Passphrase for --encrypt. Defaults to the saved passphrase.
     *
     * [--upload]
     * : Upload the bundle to the configured cloud storage.
     *
     * [--push=<key>]
     * : Send the bundle to another site, using a transfer key created on that site.
     *
     * [--push-restore]
     * : With --push, restore the bundle on the other site (overwrites it).
     *
     * ## EXAMPLES
     *
     *     wp slice-backup export
     *     wp slice-backup export --preset=db_only
     *     wp slice-backup export --components=db,uploads --exclude-revisions
     *
     * @param string[]             $args
     * @param array<string, mixed> $assocArgs
     */
    public function export(array $args, array $assocArgs): void {
        $options = array(
            'export_type'                => (string) ($assocArgs['preset'] ?? 'full'),
            'exclude_spam'               => !empty($assocArgs['exclude-spam']),
            'exclude_revisions'          => !empty($assocArgs['exclude-revisions']),
            'exclude_transients'         => !empty($assocArgs['exclude-transients']),
            'encrypt'                    => !empty($assocArgs['encrypt']),
            'passphrase'                 => (string) ($assocArgs['passphrase'] ?? ''),
            'upload'                     => !empty($assocArgs['upload']),
            'push_key'                   => (string) ($assocArgs['push'] ?? ''),
            'push_restore'               => !empty($assocArgs['push-restore']),
        );

        if (!empty($assocArgs['components'])) {
            $chosen = array_map('trim', explode(',', (string) $assocArgs['components']));
            $bad    = array_diff($chosen, MigratorEngine::COMPONENTS);
            if (!empty($bad)) {
                \WP_CLI::error('Unknown component(s): ' . implode(', ', $bad));
            }
            foreach (MigratorEngine::COMPONENTS as $key) {
                $options['include_' . $key] = in_array($key, $chosen, true);
            }
        }

        if (!empty($assocArgs['subsite'])) {
            $options['multisite_mode']             = 'subsite';
            $options['subsite_id']                 = (int) $assocArgs['subsite'];
            $options['convert_subsite_standalone'] = !empty($assocArgs['standalone']);
        }

        \WP_CLI::log('Creating bundle...');
        try {
            $job    = MigratorEngine::runToCompletion(MigratorEngine::startExport($options, 'cli'), array($this, 'progress'));
            if ($job->status !== 'done') {
                throw new \RuntimeException($job->error);
            }
            $result = (array) $job->data['result'];
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }

        \WP_CLI::success(sprintf('Created %s (%s) in %s%s', $result['bundleFile'], $result['formattedSize'], Storage::dir(), !empty($result['uploaded']) ? ' and uploaded it to cloud storage' : ''));
        foreach ($result['deletedOldBundles'] as $old) {
            \WP_CLI::log('Removed old bundle (retention setting): ' . $old);
        }
    }

    /**
     * Restores a bundle into this site.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to a bundle, or the name of a bundle already in the backup folder.
     *
     * [--restore=<list>]
     * : Comma-separated components to restore.
     * ---
     * default: db,uploads
     * ---
     *
     * [--old-url=<url>]
     * : URL to replace. Defaults to the source site URL stored in the bundle.
     *
     * [--new-url=<url>]
     * : Replacement URL. Defaults to this site's URL.
     *
     * [--isolate]
     * : Deactivate plugins that would crash on this PHP version. On by default; use --no-isolate to turn off.
     *
     * [--passphrase=<passphrase>]
     * : Passphrase of an encrypted (.slicebk) bundle. Defaults to the saved passphrase.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp slice-backup import ~/example-com-full-20260101-120000-abc.zip --restore=db,uploads,plugins,themes
     *
     * @param string[]             $args
     * @param array<string, mixed> $assocArgs
     */
    public function import(array $args, array $assocArgs): void {
        $file = (string) $args[0];
        $name = $this->ensureInStorage($file);

        $restore = array_map('trim', explode(',', (string) ($assocArgs['restore'] ?? 'db,uploads')));
        $bad     = array_diff($restore, MigratorEngine::COMPONENTS);
        if (!empty($bad)) {
            \WP_CLI::error('Unknown component(s): ' . implode(', ', $bad));
        }

        \WP_CLI::confirm(sprintf('Restore %s into %s? This overwrites: %s.', $name, (string) get_option('siteurl'), implode(', ', $restore)), $assocArgs);

        $options = array(
            'old_url'           => (string) ($assocArgs['old-url'] ?? ''),
            'new_url'           => (string) ($assocArgs['new-url'] ?? ''),
            'isolate_conflicts' => (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'isolate', true),
            'passphrase'        => (string) ($assocArgs['passphrase'] ?? ''),
        );
        foreach (MigratorEngine::COMPONENTS as $key) {
            $options['restore_' . $key] = in_array($key, $restore, true);
        }

        \WP_CLI::log('Restoring ' . $name . '...');
        try {
            $job = MigratorEngine::runToCompletion(MigratorEngine::startRestore($name, $options, 'cli'), array($this, 'progress'));
            if ($job->status !== 'done') {
                throw new \RuntimeException($job->error);
            }
            $result = (array) $job->data['result'];
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }

        $db = $result['summary']['database'];
        if (is_array($db)) {
            \WP_CLI::log(sprintf('Database: %d statements, %d errors.', $db['statements'], $db['errors']));
            foreach ($db['errorSamples'] as $sample) {
                \WP_CLI::warning($sample);
            }
        }
        foreach ($result['summary']['notes'] as $note) {
            \WP_CLI::log('Note: ' . $note);
        }
        foreach ($result['compatibilityReport']['items'] as $item) {
            if ($item['status'] !== 'compatible') {
                \WP_CLI::warning(sprintf('%s %s: %s %s', ucfirst($item['type']), $item['name'], $item['issue'], $item['actionTaken']));
            }
        }
        if (!empty($result['summary']['skippedEntries'])) {
            \WP_CLI::warning(sprintf('%d damaged archive entries were skipped.', count($result['summary']['skippedEntries'])));
        }

        \WP_CLI::success('Restore finished.');
    }

    /**
     * Lists stored bundles.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * @subcommand list
     *
     * @param string[]             $args
     * @param array<string, mixed> $assocArgs
     */
    public function list_(array $args, array $assocArgs): void {
        $rows = array();
        foreach (Storage::listBundles() as $bundle) {
            $rows[] = array(
                'file'       => $bundle['filename'],
                'size'       => $bundle['formattedSize'],
                'created'    => gmdate('Y-m-d H:i:s', (int) $bundle['created']),
                'source'     => is_array($bundle['manifest']) ? $bundle['manifest']['siteurl'] : '',
                'components' => is_array($bundle['manifest']) ? implode(',', $bundle['manifest']['components']) : '',
            );
        }
        \WP_CLI\Utils\format_items((string) ($assocArgs['format'] ?? 'table'), $rows, array('file', 'size', 'created', 'source', 'components'));
    }

    /**
     * Deletes a stored bundle.
     *
     * ## OPTIONS
     *
     * <file>
     * : Bundle file name.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * @param string[]             $args
     * @param array<string, mixed> $assocArgs
     */
    public function delete(array $args, array $assocArgs): void {
        \WP_CLI::confirm(sprintf('Delete %s?', $args[0]), $assocArgs);
        try {
            Storage::delete((string) $args[0]);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
        \WP_CLI::success('Deleted.');
    }

    /**
     * Fetches a bundle from another site into this site's backup folder.
     *
     * ## OPTIONS
     *
     * <key>
     * : Transfer key created on the other site (Settings > Site-to-site transfer).
     *
     * [--preset=<preset>]
     * : What to copy.
     * ---
     * default: full
     * options:
     *   - full
     *   - db_only
     *   - media_only
     * ---
     *
     * ## EXAMPLES
     *
     *     wp slice-backup pull slicebk1.eyJ1Ijoi... && wp slice-backup list
     *
     * @param string[]             $args
     * @param array<string, mixed> $assocArgs
     */
    public function pull(array $args, array $assocArgs): void {
        try {
            $job = MigratorEngine::runToCompletion(MigratorEngine::startPull((string) $args[0], array('export_type' => (string) ($assocArgs['preset'] ?? 'full')), 'cli'), array($this, 'progress'));
            if ($job->status !== 'done') {
                throw new \RuntimeException($job->error);
            }
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        \WP_CLI::success(sprintf('Fetched %s. Restore it with: wp slice-backup import %s', $job->data['result']['bundleFile'], $job->data['result']['bundleFile']));
    }

    /**
     * Tests the saved cloud storage settings.
     *
     * @subcommand cloud-test
     *
     * @param string[]             $args
     * @param array<string, mixed> $assocArgs
     */
    public function cloud_test(array $args, array $assocArgs): void {
        try {
            \WP_CLI::success(Cloud::test(Cloud::config())['message']);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Prints job progress (used as a callback).
     *
     * @internal
     */
    public function progress(Job $job): void {
        static $last = '';
        $line = sprintf('[%5.1f%%] %s %s', (float) $job->progress['percent'], $job->progress['label'], $job->progress['detail']);
        if ($line !== $last) {
            \WP_CLI::log($line);
            $last = $line;
        }
    }

    /**
     * Copies a bundle given by path into the backup folder and returns its file name.
     */
    private function ensureInStorage(string $file): string {
        $dir = Storage::dir();

        if (is_file($file)) {
            $real = wp_normalize_path((string) realpath($file));
            if (dirname($real) === $dir) {
                return basename($real);
            }
            $name = wp_unique_filename($dir, sanitize_file_name(basename($file)));
            if (!Storage::isBundleName($name)) {
                \WP_CLI::error('The bundle must be a .zip file.');
            }
            \WP_CLI::log('Copying bundle into ' . $dir . '...');
            if (!copy($file, $dir . '/' . $name)) {
                \WP_CLI::error('Could not copy the bundle into the backup folder.');
            }
            return $name;
        }

        $name = sanitize_file_name(basename($file));
        if (!is_file($dir . '/' . $name)) {
            \WP_CLI::error('Bundle not found: ' . $file);
        }
        return $name;
    }
}
