<?php
/**
 * Export / restore / cloud transfer jobs, run as resumable steps.
 *
 * Every step works until a time budget is used up, saves its position and returns.
 * The next request (from the browser, a loopback request or WP-Cron) continues where
 * it left off, so large sites are not stopped by PHP or proxy time limits.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

// Restoring a database means writing the site's tables directly and uncached.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Job orchestration.
 */
class MigratorEngine {
    /** Component keys, in display order. */
    public const COMPONENTS = array('db', 'uploads', 'plugins', 'themes', 'mu_plugins', 'languages', 'root_files', 'wp_core');

    /** Export presets. */
    public const PRESETS = array(
        'full'         => array('db', 'uploads', 'plugins', 'themes', 'mu_plugins', 'languages', 'root_files'),
        'turnkey_full' => array('db', 'uploads', 'plugins', 'themes', 'mu_plugins', 'languages', 'root_files', 'wp_core'),
        'db_only'      => array('db'),
        'media_only'   => array('uploads'),
    );

    private const PRESET_FILE_LABELS = array(
        'full'         => 'full',
        'turnkey_full' => 'full-core',
        'db_only'      => 'db',
        'media_only'   => 'media',
    );

    public const TICK_HOOK = 'slice_backup_job_tick';

    /**
     * @param mixed $value
     */
    public static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    public static function resolveComponents(array $options): array {
        $granular = false;
        foreach (self::COMPONENTS as $key) {
            if (array_key_exists('include_' . $key, $options)) {
                $granular = true;
                break;
            }
        }
        $preset = sanitize_key((string) ($options['export_type'] ?? 'full'));
        $list   = self::PRESETS[$preset] ?? self::PRESETS['full'];

        $selected = array();
        foreach (self::COMPONENTS as $key) {
            $selected[$key] = $granular ? self::truthy($options['include_' . $key] ?? false) : in_array($key, $list, true);
        }
        return $selected;
    }

    /* ------------------------------------------------------------------ */
    /* Starting jobs                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $options
     * @throws \Throwable When the options are invalid or another job runs.
     */
    public static function startExport(array $options, string $origin = 'manual'): Job {
        $components = self::resolveComponents($options);
        if (!in_array(true, $components, true)) {
            throw new \InvalidArgumentException(esc_html__('Nothing to export. Select at least one item to include.', 'slice-backup'));
        }

        $mode      = (is_multisite() && ($options['multisite_mode'] ?? '') === 'subsite') ? 'subsite' : 'entire_network';
        $subsiteId = $mode === 'subsite' ? absint($options['subsite_id'] ?? 0) : 0;
        if ($mode === 'subsite' && ($subsiteId < 1 || !get_site($subsiteId))) {
            throw new \InvalidArgumentException(esc_html__('Choose a valid network site to export.', 'slice-backup'));
        }

        $encrypt    = self::truthy($options['encrypt'] ?? false);
        $passphrase = (string) ($options['passphrase'] ?? '');
        if ($encrypt) {
            if (!Crypto::available()) {
                throw new \InvalidArgumentException(esc_html__('Encryption needs the PHP OpenSSL extension.', 'slice-backup'));
            }
            if ($passphrase === '') {
                $passphrase = Plugin::storedPassphrase();
            }
            if (strlen($passphrase) < 8) {
                throw new \InvalidArgumentException(esc_html__('Enter an encryption passphrase of at least 8 characters, or save one under Settings.', 'slice-backup'));
            }
        }

        $upload = self::truthy($options['upload'] ?? false);
        if ($upload && !Cloud::isConfigured()) {
            throw new \InvalidArgumentException(esc_html__('Cloud storage is not set up. Configure it under Settings > Cloud storage.', 'slice-backup'));
        }

        // Site-to-site push: check the key and the other site before starting.
        $pushKey  = trim((string) ($options['push_key'] ?? ''));
        $pushInfo = array();
        if ($pushKey !== '') {
            $pushInfo = Peer::ping(Peer::parseKey($pushKey));
            if (!empty($pushInfo['busy'])) {
                throw new \RuntimeException(esc_html__('The other site is running a backup or restore right now. Try again when it has finished.', 'slice-backup'));
            }
        }
        $pushRestore = $pushKey !== '' && self::truthy($options['push_restore'] ?? false);

        $context = array(
            'multisite_mode'             => $mode,
            'subsite_id'                 => $subsiteId,
            'convert_subsite_standalone' => $mode === 'subsite' && self::truthy($options['convert_subsite_standalone'] ?? false),
            'exclude_spam'               => self::truthy($options['exclude_spam'] ?? false),
            'exclude_revisions'          => self::truthy($options['exclude_revisions'] ?? false),
            'exclude_transients'         => self::truthy($options['exclude_transients'] ?? false),
        );

        $steps = array();
        if ($components['db']) {
            $steps[] = 'dump';
        }
        array_push($steps, 'scan', 'archive');
        if ($encrypt) {
            $steps[] = 'encrypt';
        }
        $steps[] = 'store';
        if ($upload) {
            $steps[] = 'upload';
        }
        if ($pushKey !== '') {
            $steps[] = 'push';
            if ($pushRestore) {
                $steps[] = 'remote_restore';
            }
        }
        $steps[] = 'finalize';

        Storage::cleanupStale();
        Job::cleanupOld();

        $job = Job::create(
            'export',
            $steps,
            array(
                'components'   => $components,
                'context'      => $context,
                'encrypt'      => $encrypt,
                'passphrase'   => $encrypt ? Crypto::seal($passphrase) : '',
                'upload'       => $upload,
                'delete_local' => $upload && self::truthy($options['delete_local'] ?? false),
                'push'         => $pushKey !== '' ? Peer::sealKey($pushKey) : '',
                'push_restore' => $pushRestore,
                'push_site'    => (string) ($pushInfo['site'] ?? ''),
                'push_chunk'   => max(262144, min(Peer::CHUNK, (int) ($pushInfo['chunkSize'] ?? Peer::CHUNK))),
            ),
            $origin
        );

        $name = Storage::newBundleName(self::fileLabel($components, $context), $origin === 'schedule');
        if ($encrypt) {
            $name = substr($name, 0, -4) . '.slicebk';
        }
        $job->data = array(
            'name'     => $name,
            'sql'      => $job->workDir() . '/database.sql',
            'list'     => $job->workDir() . '/files.txt',
            'zip'      => $job->workDir() . '/bundle.zip',
            'manifest' => StreamingArchiver::manifest($components, $context, $components['db'], $origin),
        );
        $job->progress = array('label' => __('Starting…', 'slice-backup'), 'percent' => 0.0, 'detail' => '');
        $job->save();

        /** Fires when an export job starts. */
        do_action('slice_backup_before_export', $options, $components);

        return $job;
    }

    /**
     * @param array<string, mixed> $options
     * @throws \Throwable When the options are invalid or another job runs.
     */
    public static function startRestore(string $filename, array $options, string $origin = 'manual'): Job {
        global $wpdb;

        $path      = Storage::path($filename);
        $encrypted = Crypto::isEncryptedFile($path);
        $sealed    = '';
        if ($encrypted) {
            $passphrase = (string) ($options['passphrase'] ?? '');
            if ($passphrase === '') {
                $passphrase = Plugin::storedPassphrase();
            }
            // Fails fast on a wrong passphrase.
            Crypto::readEncryptedManifest($path, $passphrase);
            $sealed = Crypto::seal($passphrase);
        }
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(esc_html__('The PHP zip extension (ZipArchive) is required. Ask your host to enable it.', 'slice-backup'));
        }

        if (isset($options['restore_core']) && !isset($options['restore_wp_core'])) {
            $options['restore_wp_core'] = $options['restore_core'];
        }
        $want = array();
        foreach (self::COMPONENTS as $key) {
            $want[$key] = self::truthy($options['restore_' . $key] ?? in_array($key, array('db', 'uploads'), true));
        }
        if (!in_array(true, $want, true)) {
            throw new \InvalidArgumentException(esc_html__('Select at least one item to restore.', 'slice-backup'));
        }

        $steps = array();
        if ($encrypted) {
            $steps[] = 'decrypt';
        }
        array_push($steps, 'extract', 'inspect', 'import', 'replace', 'files', 'shield', 'finalize');

        Storage::cleanupStale();
        Job::cleanupOld();

        $job = Job::create(
            'restore',
            $steps,
            array(
                'filename'           => $filename,
                'want'               => $want,
                'isolate'            => self::truthy($options['isolate_conflicts'] ?? true),
                'old_url'            => esc_url_raw((string) ($options['old_url'] ?? '')),
                'new_url'            => esc_url_raw((string) ($options['new_url'] ?? '')),
                'old_network_domain' => sanitize_text_field((string) ($options['old_network_domain'] ?? '')),
                'new_network_domain' => sanitize_text_field((string) ($options['new_network_domain'] ?? '')),
                'passphrase'         => $sealed,
            ),
            $origin
        );

        // Read these before the database is replaced.
        $job->data = array(
            'zip'           => $encrypted ? $job->workDir() . '/bundle.zip' : $path,
            'extract'       => $job->workDir() . '/extract',
            'targetSiteUrl' => untrailingslashit((string) get_option('siteurl')),
            'targetHomeUrl' => untrailingslashit((string) get_option('home')),
            'targetPrefix'  => (string) $wpdb->base_prefix,
            'summary'       => array('skippedEntries' => array(), 'database' => null, 'replace' => null, 'files' => array(), 'notes' => array()),
        );
        $job->save();

        /** Fires when a restore job starts. */
        do_action('slice_backup_before_restore', $filename, $options);

        return $job;
    }

    /**
     * Uploads an existing local bundle to cloud storage.
     *
     * @throws \Throwable On invalid input.
     */
    public static function startUpload(string $filename, string $origin = 'manual'): Job {
        $path = Storage::path($filename);
        if (!Cloud::isConfigured()) {
            throw new \InvalidArgumentException(esc_html__('Cloud storage is not set up.', 'slice-backup'));
        }
        $job       = Job::create('upload', array('upload', 'finalize'), array('filename' => $filename), $origin);
        $job->data = array('name' => $filename, 'file' => $path);
        $job->save();
        return $job;
    }

    /**
     * Pulls a bundle from another site: asks it to create one, then downloads it here.
     *
     * @param array<string, mixed> $options export_type.
     * @throws \Throwable On an invalid key or when either site is busy.
     */
    public static function startPull(string $key, array $options, string $origin = 'manual'): Job {
        $info = Peer::ping(Peer::parseKey($key));
        if (!empty($info['busy'])) {
            throw new \RuntimeException(esc_html__('The other site is running a backup or restore right now. Try again when it has finished.', 'slice-backup'));
        }
        $preset = sanitize_key((string) ($options['export_type'] ?? 'full'));
        $job    = Job::create(
            'pull',
            array('remote_export', 'pull_download', 'finalize'),
            array(
                'key'         => Peer::sealKey($key),
                'export_type' => isset(self::PRESETS[$preset]) ? $preset : 'full',
                'site'        => (string) ($info['site'] ?? ''),
            ),
            $origin
        );
        $job->data = array('part' => $job->workDir() . '/download.part');
        $job->save();
        return $job;
    }

    /**
     * Follows a job running on the other site until the deadline. Returns its result when done.
     *
     * @param array{url: string, id: string, secret: string} $key
     * @param array<string, mixed>                          $state Contains id and token.
     * @return array<string, mixed>|null
     * @throws \RuntimeException When the remote job failed.
     */
    private static function followRemote(Job $job, array $key, array $state, float $deadline, string $label): ?array {
        $first = true;
        while ($first || microtime(true) < $deadline) {
            $first  = false;
            $remote = Peer::request($key, 'job', (string) wp_json_encode(array('id' => $state['id'], 'token' => $state['token'])), array(), false, 90)['json'];
            $p      = (array) ($remote['progress'] ?? array());
            self::setProgress($job, $label . ': ' . (string) ($p['label'] ?? ''), ((float) ($p['percent'] ?? 0)) / 100, (string) ($p['detail'] ?? ''));

            $status = (string) ($remote['status'] ?? '');
            if ($status === 'done') {
                return (array) ($remote['result'] ?? array());
            }
            if ($status !== 'running') {
                throw new \RuntimeException(
                    esc_html(/* translators: %s: error message from the other site. */
                    sprintf(__('The other site reported: %s', 'slice-backup'), (string) ($remote['error'] ?? $status)))
                );
            }
        }
        return null;
    }

    /**
     * Downloads a bundle from cloud storage into the backup folder.
     *
     * @throws \Throwable On invalid input.
     */
    public static function startDownload(string $filename, string $origin = 'manual'): Job {
        if (!Storage::isBundleName($filename)) {
            throw new \InvalidArgumentException(esc_html__('Invalid bundle file name.', 'slice-backup'));
        }
        if (is_file(Storage::dir() . '/' . $filename)) {
            throw new \InvalidArgumentException(esc_html__('This bundle is already on the server.', 'slice-backup'));
        }
        if (!Cloud::isConfigured()) {
            throw new \InvalidArgumentException(esc_html__('Cloud storage is not set up.', 'slice-backup'));
        }
        $job       = Job::create('download', array('download', 'finalize'), array('filename' => $filename), $origin);
        $job->data = array('name' => $filename, 'part' => $job->workDir() . '/download.part');
        $job->save();
        return $job;
    }

    /* ------------------------------------------------------------------ */
    /* Running jobs                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Works on the job for up to $budget seconds. Safe to call from several requests:
     * only one works at a time, the others return the current status.
     */
    public static function tick(Job $job, float $budget): Job {
        if ($job->status !== 'running') {
            return $job;
        }
        $lock = $job->lockTick();
        if ($lock === null) {
            return $job;
        }

        // Reload under the lock in case another request advanced it.
        $fresh = Job::load($job->id);
        if ($fresh !== null) {
            $job = $fresh;
        }

        self::prepareEnvironment();
        $deadline = microtime(true) + max(3.0, $budget);

        try {
            while ($job->status === 'running' && microtime(true) < $deadline) {
                $step = $job->step();
                if ($step === '') {
                    $job->finish();
                    break;
                }
                if (!isset($job->state[$step])) {
                    $job->state[$step] = array();
                }
                $stepState = $job->state[$step];
                $done      = self::runStep($job, $step, $stepState, $deadline, $budget);
                $job->state[$step] = $stepState;

                if ($done) {
                    unset($job->state[$step]);
                    $job->stepIndex++;
                    if ($job->stepIndex >= count($job->steps)) {
                        $job->finish();
                        break;
                    }
                }
                $job->save();
            }
        } catch (\Throwable $e) {
            self::onFailure($job, Plugin::errorText($e));
        } finally {
            $job->unlockTick($lock);
        }

        if ($job->status === 'running') {
            self::scheduleBackgroundTick($job);
        }
        return $job;
    }

    /**
     * Runs a job until it ends (WP-CLI, REST, tests).
     *
     * @param callable|null $onProgress Receives the job after every tick.
     */
    public static function runToCompletion(Job $job, ?callable $onProgress = null): Job {
        while ($job->status === 'running') {
            $job = self::tick($job, 60.0);
            if ($onProgress !== null) {
                $onProgress($job);
            }
            if ($job->status === 'running') {
                usleep(100000);
            }
        }
        return $job;
    }

    /**
     * Keeps a job moving without a browser: a non-blocking loopback request plus a
     * WP-Cron event as a fallback (for hosts that block loopback requests).
     */
    public static function scheduleBackgroundTick(Job $job): void {
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        $at = time() + 30;
        if (!wp_next_scheduled(self::TICK_HOOK, array($job->id))) {
            wp_schedule_single_event($at, self::TICK_HOOK, array($job->id));
        }

        wp_remote_post(
            admin_url('admin-ajax.php'),
            array(
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => (bool) apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
                'body'      => array(
                    'action' => 'slice_backup_job_tick',
                    'job'    => $job->id,
                    'token'  => $job->token,
                    'source' => 'loopback',
                ),
            )
        );
    }

    /**
     * WP-Cron handler for background ticks.
     *
     * @param string $jobId
     */
    public static function cronTick($jobId = ''): void {
        $job = Job::active();
        if ($job !== null && ($jobId === '' || $job->id === $jobId)) {
            self::tick($job, (float) Plugin::settings()['step_seconds']);
        }
    }

    /**
     * Cancels the active job and cleans up.
     */
    public static function cancel(Job $job): void {
        if ($job->type === 'export' && isset($job->state['upload'])) {
            Cloud::abortUpload((array) $job->state['upload']);
        }
        if ($job->type === 'upload' && isset($job->state['upload'])) {
            Cloud::abortUpload((array) $job->state['upload']);
        }
        $job->cancel();
        self::cleanupWork($job, true);
    }

    private static function onFailure(Job $job, string $message): void {
        if (isset($job->state['upload'])) {
            Cloud::abortUpload((array) $job->state['upload']);
        }
        $job->fail($message);
        self::cleanupWork($job, true);

        if ($job->origin === 'schedule') {
            Notifier::backupResult(false, $job, null);
        }
    }

    /**
     * Deletes temporary files of a job (keeps state.json).
     */
    private static function cleanupWork(Job $job, bool $failed): void {
        $dir = $job->workDir();
        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || in_array($entry, array('.', '..', 'state.json', 'tick.lock'), true)) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                Storage::removeDirectory($path);
            } else {
                wp_delete_file($path);
            }
        }
        // A failed download leaves no partial bundle behind.
        if ($failed && in_array($job->type, array('download', 'pull'), true) && !empty($job->data['final']) && is_file((string) $job->data['final'])) {
            wp_delete_file((string) $job->data['final']);
        }
    }

    private static function prepareEnvironment(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running step; each step is still time-budgeted.
        }
        ignore_user_abort(true);
        wp_raise_memory_limit('admin');
    }

    /**
     * Truncates an append-only output file back to the size recorded at the last save,
     * so a request that died mid-step does not leave duplicate data behind.
     *
     * @param array<string, mixed> $state
     */
    private static function rewind(string $file, array $state): void {
        if (isset($state['_size']) && is_file($file) && Storage::filesize($file) > (float) $state['_size']) {
            $handle = fopen($file, 'r+b'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
            if ($handle) {
                ftruncate($handle, (int) $state['_size']);
                fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function remember(string $file, array &$state): void {
        clearstatcache(true, $file);
        $state['_size'] = is_file($file) ? Storage::filesize($file) : 0.0;
    }

    private static function setProgress(Job $job, string $label, float $stepFraction, string $detail = ''): void {
        $count   = max(1, count($job->steps));
        $percent = (($job->stepIndex + max(0.0, min(1.0, $stepFraction))) / $count) * 100;
        $job->progress = array('label' => $label, 'percent' => round($percent, 1), 'detail' => $detail);
    }

    /**
     * Runs one step until the deadline. Returns true when the step is complete.
     *
     * @param array<string, mixed> $state
     * @throws \Throwable On errors.
     */
    private static function runStep(Job $job, string $step, array &$state, float $deadline, float $budget): bool {
        switch ($step) {
            /* Export ------------------------------------------------------ */
            case 'dump':
                $dumper = new ChunkedDatabaseDumper();
                $file   = (string) $job->data['sql'];
                if (empty($state)) {
                    $state = $dumper->begin((array) $job->options['context'], $file);
                } else {
                    self::rewind($file, $state);
                }
                $done = $dumper->step($state, (array) $job->options['context'], $deadline);
                self::remember($file, $state);
                $total = max(1, count($state['tables']));
                self::setProgress(
                    $job,
                    __('Exporting the database', 'slice-backup'),
                    $state['ti'] / $total,
                    /* translators: 1: tables done, 2: total tables, 3: rows. */
                    sprintf(__('%1$d of %2$d tables, %3$s rows', 'slice-backup'), min($state['ti'], $total), $total, number_format_i18n((int) $state['rows']))
                );
                return $done;

            case 'scan':
                $file = (string) $job->data['list'];
                if (empty($state)) {
                    $state = StreamingArchiver::beginScan((array) $job->options['components'], (array) $job->options['context'], $file);
                } else {
                    self::rewind($file, $state);
                }
                $done = StreamingArchiver::scanStep($state, $deadline);
                self::remember($file, $state);
                self::setProgress(
                    $job,
                    __('Finding files', 'slice-backup'),
                    $done ? 1.0 : 0.5,
                    /* translators: 1: number of files, 2: size. */
                    sprintf(__('%1$s files, %2$s', 'slice-backup'), number_format_i18n((int) $state['files']), size_format((float) $state['bytes'], 1))
                );
                if ($done) {
                    $job->data['files'] = (int) $state['files'];
                    $job->data['bytes'] = (float) $state['bytes'];
                }
                return $done;

            case 'archive':
                if (empty($state)) {
                    $hasDb = in_array('dump', $job->steps, true);
                    $state = StreamingArchiver::beginArchive((string) $job->data['list'], $hasDb ? (string) $job->data['sql'] : null, (array) $job->data['manifest'], (string) $job->data['zip'], (int) ($job->data['files'] ?? 0), (float) ($job->data['bytes'] ?? 0));
                }
                $byteBudget = max(64.0, $budget * 15.0) * MB_IN_BYTES;
                $done       = StreamingArchiver::archiveStep($state, $deadline, $byteBudget);
                $fraction   = (float) $state['totalBytes'] > 0 ? (float) $state['addedBytes'] / (float) $state['totalBytes'] : ($done ? 1.0 : 0.0);
                self::setProgress(
                    $job,
                    __('Compressing files', 'slice-backup'),
                    $fraction,
                    /* translators: 1: files added, 2: total files. */
                    sprintf(__('%1$s of %2$s files', 'slice-backup'), number_format_i18n((int) $state['added']), number_format_i18n((int) $state['total']))
                );
                if ($done) {
                    wp_delete_file((string) $job->data['list']);
                    wp_delete_file((string) $job->data['sql']);
                    $job->data['file'] = (string) $job->data['zip'];
                }
                return $done;

            case 'encrypt':
                $source = (string) $job->data['zip'];
                $target = $job->workDir() . '/bundle.slicebk';
                if (!empty($state)) {
                    self::rewind($target, $state);
                }
                $done = Crypto::encryptStep($source, $target, Crypto::open((string) $job->options['passphrase']), (array) $job->data['manifest'], $state, $deadline);
                self::remember($target, $state);
                $size = max(1.0, Storage::filesize($source));
                self::setProgress($job, __('Encrypting', 'slice-backup'), (float) $state['offset'] / $size, size_format((float) $state['offset'], 1) . ' / ' . size_format($size, 1));
                if ($done) {
                    wp_delete_file($source);
                    $job->data['file'] = $target;
                }
                return $done;

            case 'store':
                $final = Storage::dir() . '/' . $job->data['name'];
                if (!@rename((string) $job->data['file'], $final)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
                    throw new \RuntimeException(esc_html__('Could not move the finished bundle into the backup folder.', 'slice-backup'));
                }
                $job->data['file'] = $final;
                self::setProgress($job, __('Saved', 'slice-backup'), 1.0);
                return true;

            case 'upload':
                $file = (string) $job->data['file'];
                $done = Cloud::uploadStep($file, (string) $job->data['name'], $state, $deadline);
                $size = max(1.0, Storage::filesize($file));
                self::setProgress($job, __('Uploading to cloud storage', 'slice-backup'), (float) ($state['offset'] ?? 0) / $size, size_format((float) ($state['offset'] ?? 0), 1) . ' / ' . size_format($size, 1));
                if ($done) {
                    $job->data['uploaded'] = true;
                    $state                 = array();
                }
                return $done;

            case 'push':
                return self::pushStep($job, $state, $deadline);

            case 'remote_restore':
                $key = Peer::openKey((string) $job->options['push']);
                if (empty($state)) {
                    $body = array(
                        'filename'          => (string) $job->data['remoteName'],
                        'isolate_conflicts' => true,
                        'passphrase'        => !empty($job->options['encrypt']) ? Crypto::open((string) $job->options['passphrase']) : '',
                    );
                    foreach ((array) $job->options['components'] as $component => $included) {
                        // Server config files and WordPress core are never applied automatically.
                        $body['restore_' . $component] = $included && !in_array($component, array('root_files', 'wp_core'), true);
                    }
                    $state = Peer::request($key, 'restore', (string) wp_json_encode($body))['json'];
                }
                $result = self::followRemote($job, $key, $state, $deadline, __('Restoring on the other site', 'slice-backup'));
                if ($result === null) {
                    return false;
                }
                $job->data['remoteResult'] = $result;
                return true;

            case 'remote_export':
                $key = Peer::openKey((string) $job->options['key']);
                if (empty($state)) {
                    $state = Peer::request($key, 'export', (string) wp_json_encode(array('export_type' => $job->options['export_type'])))['json'];
                }
                $result = self::followRemote($job, $key, $state, $deadline, __('Creating the bundle on the other site', 'slice-backup'));
                if ($result === null) {
                    return false;
                }
                if (empty($result['bundleFile']) || !Storage::isBundleName((string) $result['bundleFile'])) {
                    throw new \RuntimeException(esc_html__('The other site did not report a bundle.', 'slice-backup'));
                }
                $job->data['name']       = (string) $result['bundleFile'];
                $job->data['remoteSize'] = (float) ($result['sizeBytes'] ?? 0);
                return true;

            case 'pull_download':
                $key  = Peer::openKey((string) $job->options['key']);
                $part = (string) $job->data['part'];
                if (empty($state)) {
                    file_put_contents($part, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    $state = array('offset' => 0.0, 'size' => (float) $job->data['remoteSize']);
                } else {
                    self::rewind($part, $state);
                    $state['offset'] = min((float) $state['offset'], Storage::filesize($part));
                }
                $first = true;
                while ((float) $state['offset'] < (float) $state['size'] && ($first || microtime(true) < $deadline)) {
                    $first = false;
                    $data  = Peer::request(
                        $key,
                        'download',
                        '{}',
                        array('filename' => (string) $job->data['name'], 'offset' => sprintf('%.0f', (float) $state['offset']), 'length' => (string) Peer::CHUNK),
                        false,
                        180
                    )['body'];
                    if ($data === '') {
                        throw new \RuntimeException(esc_html__('The other site sent an empty response.', 'slice-backup'));
                    }
                    Cloud::appendChunk($part, $data);
                    $state['offset'] = (float) $state['offset'] + strlen($data);
                }
                self::remember($part, $state);
                $size = max(1.0, (float) $state['size']);
                self::setProgress($job, __('Downloading from the other site', 'slice-backup'), (float) $state['offset'] / $size, size_format((float) $state['offset'], 1) . ' / ' . size_format($size, 1));
                return (float) $state['offset'] >= (float) $state['size'];

            case 'download':
                $part = (string) $job->data['part'];
                if (!empty($state)) {
                    self::rewind($part, $state);
                    $state['offset'] = min((float) $state['offset'], Storage::filesize($part));
                }
                $done = Cloud::downloadStep((string) $job->data['name'], $part, $state, $deadline);
                self::remember($part, $state);
                $size = max(1.0, (float) ($state['size'] ?? 1));
                self::setProgress($job, __('Downloading from cloud storage', 'slice-backup'), (float) $state['offset'] / $size, size_format((float) $state['offset'], 1) . ' / ' . size_format($size, 1));
                return $done;

            /* Restore ----------------------------------------------------- */
            case 'decrypt':
                $source = Storage::path((string) $job->options['filename']);
                $target = (string) $job->data['zip'];
                if (!empty($state)) {
                    self::rewind($target, $state);
                }
                $done = Crypto::decryptStep($source, $target, Crypto::open((string) $job->options['passphrase']), $state, $deadline);
                self::remember($target, $state);
                $size = max(1.0, Storage::filesize($source));
                self::setProgress($job, __('Decrypting', 'slice-backup'), (float) $state['offset'] / $size);
                return $done;

            case 'extract':
                $inspector = new CompatibilityInspector();
                $done      = $inspector->extractStep((string) $job->data['zip'], (string) $job->data['extract'], $state, $deadline);
                $total     = max(1, (int) ($state['total'] ?? 1));
                self::setProgress(
                    $job,
                    __('Unpacking the bundle', 'slice-backup'),
                    (int) $state['index'] / $total,
                    /* translators: 1: entries done, 2: total entries. */
                    sprintf(__('%1$s of %2$s files', 'slice-backup'), number_format_i18n((int) $state['index']), number_format_i18n($total))
                );
                if ($done) {
                    $job->data['summary']['skippedEntries'] = (array) $state['skipped'];
                    $manifestFile = $job->data['extract'] . '/bundle.json';
                    $manifest     = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    $job->data['manifest'] = is_array($manifest) ? $manifest : array();
                    if ((string) $job->data['zip'] !== Storage::dir() . '/' . $job->options['filename']) {
                        wp_delete_file((string) $job->data['zip']);
                    }
                }
                return $done;

            case 'inspect':
                $want = (array) $job->options['want'];
                $job->data['report'] = (new CompatibilityInspector())->inspectCompatibility(
                    (string) $job->data['extract'],
                    (array) $job->data['manifest'],
                    array('plugins' => !empty($want['plugins']), 'themes' => !empty($want['themes']))
                );
                self::setProgress($job, __('Checking plugins and themes', 'slice-backup'), 1.0);
                return true;

            case 'import':
                return self::importStep($job, $state, $deadline);

            case 'replace':
                return self::replaceStep($job, $state, $deadline);

            case 'files':
                return self::filesStep($job, $state, $deadline);

            case 'shield':
                $report = (array) $job->data['report'];
                (new CompatibilityInspector())->applyShield($report, !empty($job->options['isolate']));
                $job->data['report'] = $report;
                self::setProgress($job, __('Protecting the site from incompatible code', 'slice-backup'), 1.0);
                return true;

            case 'finalize':
                self::finalize($job);
                return true;
        }

        throw new \RuntimeException(esc_html(sprintf('Unknown job step: %s', $step)));
    }

    /**
     * Sends the bundle to the other site in pieces; resumes from the offset the other site has.
     *
     * @param array<string, mixed> $state
     */
    private static function pushStep(Job $job, array &$state, float $deadline): bool {
        $key   = Peer::openKey((string) $job->options['push']);
        $file  = (string) $job->data['file'];
        $size  = Storage::filesize($file);
        $chunk = (int) $job->options['push_chunk'];
        if (empty($state)) {
            $state = array('id' => bin2hex(random_bytes(16)), 'offset' => 0.0);
        }

        $handle = fopen($file, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            throw new \RuntimeException(esc_html__('Could not read the bundle for sending.', 'slice-backup'));
        }
        try {
            $first = true;
            while (empty($state['done']) && ($first || microtime(true) < $deadline)) {
                $first = false;
                Crypto::seek($handle, (float) $state['offset']);
                $data = (string) fread($handle, $chunk); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                try {
                    $result = Peer::request(
                        $key,
                        'upload',
                        $data,
                        array(
                            'upload_id' => (string) $state['id'],
                            'offset'    => sprintf('%.0f', (float) $state['offset']),
                            'total'     => sprintf('%.0f', $size),
                            'filename'  => (string) $job->data['name'],
                        ),
                        true,
                        300
                    )['json'];
                } catch (PeerError $e) {
                    $expected = $e->data['data']['expected'] ?? null;
                    if ($e->status === 409 && is_numeric($expected)) {
                        $state['offset'] = (float) $expected;
                        continue;
                    }
                    throw $e;
                }
                if (!empty($result['complete'])) {
                    $state['done']            = true;
                    $state['offset']          = $size;
                    $job->data['remoteName']  = (string) $result['filename'];
                } else {
                    $state['offset'] = (float) ($result['received'] ?? ((float) $state['offset'] + strlen($data)));
                }
            }
        } finally {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        self::setProgress(
            $job,
            sprintf(
                /* translators: %s: site address. */
                __('Sending to %s', 'slice-backup'),
                (string) wp_parse_url((string) $job->options['push_site'], PHP_URL_HOST)
            ),
            (float) $state['offset'] / max(1.0, $size),
            size_format((float) $state['offset'], 1) . ' / ' . size_format($size, 1)
        );
        return !empty($state['done']);
    }

    /**
     * @param array<string, mixed> $state
     * @throws \RuntimeException When the SQL file cannot be read.
     */
    private static function importStep(Job $job, array &$state, float $deadline): bool {
        global $wpdb;

        $want = (array) $job->options['want'];
        $file = $job->data['extract'] . '/database.sql';
        if (empty($want['db'])) {
            return true;
        }
        if (!is_file($file)) {
            $job->data['summary']['notes'][] = __('The bundle does not contain a database, so the database was not changed.', 'slice-backup');
            return true;
        }

        $manifest     = (array) $job->data['manifest'];
        $targetPrefix = (string) $job->data['targetPrefix'];
        $sourcePrefix = isset($manifest['table_prefix']) && preg_match('/^[A-Za-z0-9_]+$/', (string) $manifest['table_prefix']) ? (string) $manifest['table_prefix'] : $targetPrefix;

        if (empty($state)) {
            $state = array('pos' => 0, 'statements' => 0, 'errors' => 0, 'errorSamples' => array(), 'fixCollation' => !DbUtil::supportsCollation('utf8mb4_0900_ai_ci'));
        }

        $handle = fopen($file, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            throw new \RuntimeException(esc_html__('Could not read the database file from the bundle.', 'slice-backup'));
        }
        Crypto::seek($handle, (float) $state['pos']);
        $size = max(1.0, Storage::filesize($file));

        $query = '';
        $eof   = false;
        $ran   = 0;
        while (true) {
            if ($query === '' && $ran > 0 && microtime(true) >= $deadline) {
                break;
            }
            $line = fgets($handle);
            if ($line === false) {
                $eof = true;
                break;
            }
            if ($query === '') {
                $trimmed = ltrim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
                    $state['pos'] = (float) $state['pos'] + strlen($line);
                    continue;
                }
            }
            $query .= $line;
            if (substr(rtrim($line), -1) !== ';') {
                continue;
            }

            $sql = rtrim($query);
            $state['pos'] = (float) $state['pos'] + strlen($query);
            $query        = '';

            if ($sourcePrefix !== $targetPrefix) {
                $sql = self::rewriteTablePrefix($sql, $sourcePrefix, $targetPrefix);
            }
            if (!empty($state['fixCollation']) && strpos($sql, 'utf8mb4_0900_') !== false) {
                $sql = (string) preg_replace('/utf8mb4_0900_[a-z_]+/', 'utf8mb4_unicode_520_ci', $sql);
            }

            $wpdb->check_current_query = false;
            if ($wpdb->query($sql) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Restoring runs the statements of the bundle's own SQL dump (values escaped when it was written). Only administrators can start a restore.
                $state['errors']++;
                if (count($state['errorSamples']) < 5) {
                    $state['errorSamples'][] = substr((string) $wpdb->last_error, 0, 300);
                }
            }
            $state['statements']++;
            $ran++;
            DbUtil::releaseMemory();
        }
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        if ($eof && trim($query) !== '') {
            $state['errors']++;
            $state['errorSamples'][] = __('The database file ended in the middle of a statement. The bundle may be truncated.', 'slice-backup');
        }

        self::setProgress(
            $job,
            __('Importing the database', 'slice-backup'),
            (float) $state['pos'] / $size,
            /* translators: %s: number of SQL statements. */
            sprintf(__('%s statements', 'slice-backup'), number_format_i18n((int) $state['statements']))
        );

        if (!$eof) {
            return false;
        }

        $wpdb->set_sql_mode();
        if ($sourcePrefix !== $targetPrefix) {
            self::renamePrefixedKeys($sourcePrefix, $targetPrefix);
            $job->data['summary']['notes'][] = sprintf(
                /* translators: 1: source table prefix, 2: this site's table prefix. */
                __('Table prefix converted from "%1$s" to "%2$s".', 'slice-backup'),
                $sourcePrefix,
                $targetPrefix
            );
        }
        wp_cache_flush();

        $job->data['dbRestored']           = true;
        $job->data['summary']['database'] = array(
            'statements'   => (int) $state['statements'],
            'errors'       => (int) $state['errors'],
            'errorSamples' => (array) $state['errorSamples'],
        );
        return true;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function replaceStep(Job $job, array &$state, float $deadline): bool {
        if (empty($job->data['dbRestored'])) {
            return true;
        }

        $replacer = new RecursiveSearchReplace();
        if (empty($state)) {
            $state = $replacer->begin(self::urlPairs($job));
        }
        $done  = $replacer->step($state, $deadline);
        $total = max(1, count($state['tables']));
        self::setProgress(
            $job,
            __('Updating addresses in the database', 'slice-backup'),
            $state['ti'] / $total,
            /* translators: 1: tables done, 2: total tables. */
            sprintf(__('%1$d of %2$d tables', 'slice-backup'), min($state['ti'], $total), $total)
        );
        if (!$done) {
            return false;
        }

        $stats         = $state['stats'];
        $stats['from'] = (string) ($job->data['urlFrom'] ?? '');
        $stats['to']   = (string) ($job->data['urlTo'] ?? '');

        if (is_multisite()) {
            $manifest  = (array) $job->data['manifest'];
            $oldDomain = (string) $job->options['old_network_domain'] !== '' ? (string) $job->options['old_network_domain'] : (string) ($manifest['multisite']['network_domain'] ?? '');
            $newDomain = (string) $job->options['new_network_domain'] !== '' ? (string) $job->options['new_network_domain'] : (defined('DOMAIN_CURRENT_SITE') ? (string) DOMAIN_CURRENT_SITE : '');
            if ($oldDomain !== '' && $newDomain !== '' && $oldDomain !== $newDomain) {
                $replacer->replaceMultisiteNetworkDomain($oldDomain, $newDomain);
                $stats['networkDomain'] = array('from' => $oldDomain, 'to' => $newDomain);
            }
        }

        delete_option('rewrite_rules');
        wp_cache_flush();
        $job->data['summary']['replace'] = $stats;
        return true;
    }

    /**
     * @return array<string, string>
     */
    private static function urlPairs(Job $job): array {
        $manifest   = (array) $job->data['manifest'];
        $targetSite = (string) $job->data['targetSiteUrl'];
        $targetHome = (string) $job->data['targetHomeUrl'];

        $sourceSite = untrailingslashit(esc_url_raw((string) ($job->options['old_url'] !== '' ? $job->options['old_url'] : ($manifest['siteurl'] ?? ''))));
        $sourceHome = untrailingslashit(esc_url_raw((string) ($manifest['home'] ?? '')));
        $newSite    = $job->options['new_url'] !== '' ? untrailingslashit((string) $job->options['new_url']) : $targetSite;
        $newHome    = $newSite === $targetSite ? $targetHome : $newSite;

        $job->data['urlFrom'] = $sourceSite;
        $job->data['urlTo']   = $newSite;

        $pairs = RecursiveSearchReplace::urlPairs($sourceSite, $newSite);
        if ($sourceHome !== '' && $sourceHome !== $sourceSite) {
            $pairs += RecursiveSearchReplace::urlPairs($sourceHome, $newHome);
        }
        return $pairs;
    }

    /**
     * Copies the extracted folders over the site, a directory at a time.
     *
     * @param array<string, mixed> $state
     */
    private static function filesStep(Job $job, array &$state, float $deadline): bool {
        $want    = (array) $job->options['want'];
        $extract = (string) $job->data['extract'];

        if (empty($state)) {
            $uploads = wp_upload_dir(null, false);
            $targets = array(
                'uploads'    => array('uploads', (string) $uploads['basedir']),
                'plugins'    => array('plugins', WP_PLUGIN_DIR),
                'themes'     => array('themes', get_theme_root()),
                'mu_plugins' => array('mu-plugins', defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins'),
                'languages'  => array('languages', defined('WP_LANG_DIR') ? WP_LANG_DIR : WP_CONTENT_DIR . '/languages'),
                'wp_core'    => array('core', untrailingslashit(ABSPATH)),
            );
            $queue = array();
            foreach ($targets as $key => $target) {
                $source = $extract . '/' . $target[0];
                if (!empty($want[$key]) && is_dir($source)) {
                    $queue[] = array('src' => $source, 'dst' => rtrim(wp_normalize_path($target[1]), '/'), 'key' => $key, 'top' => true, 'i' => 0);
                }
            }
            $state = array('queue' => $queue, 'stats' => array(), 'done' => 0);

            if (!empty($want['root_files']) && is_dir($extract . '/root')) {
                $notes = (array) $job->data['summary']['notes'];
                $state['stats']['root_files'] = self::restoreRootFiles($extract . '/root', ABSPATH, $notes);
                $job->data['summary']['notes'] = $notes;
            }
        }

        $self   = basename(untrailingslashit(SLICE_BACKUP_PATH));
        $copies = 0;
        while (!empty($state['queue']) && ($copies === 0 || microtime(true) < $deadline)) {
            $item    = $state['queue'][0];
            $key     = (string) $item['key'];
            $entries = @scandir((string) $item['src']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $entries = is_array($entries) ? array_values(array_diff($entries, array('.', '..'))) : array();
            wp_mkdir_p((string) $item['dst']);

            if (!isset($state['stats'][$key])) {
                $state['stats'][$key] = array('copied' => 0, 'failed' => 0);
            }

            $count = count($entries);
            for ($i = (int) $item['i']; $i < $count; $i++) {
                if ($copies > 0 && microtime(true) >= $deadline) {
                    $state['queue'][0]['i'] = $i;
                    break 2;
                }
                $copies++;
                $name = $entries[$i];
                // Never overwrite this plugin while it is running.
                if (!empty($item['top']) && $key === 'plugins' && $name === $self) {
                    continue;
                }
                $src = $item['src'] . '/' . $name;
                $dst = $item['dst'] . '/' . $name;
                if (is_dir($src)) {
                    $state['queue'][] = array('src' => $src, 'dst' => $dst, 'key' => $key, 'top' => false, 'i' => 0);
                } elseif (@copy($src, $dst)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    $state['stats'][$key]['copied']++;
                    $state['done']++;
                } else {
                    $state['stats'][$key]['failed']++;
                }
            }
            array_shift($state['queue']);
        }

        $total = max(1, (int) ($job->state['extract']['total'] ?? $state['done'] + count($state['queue'])));
        self::setProgress(
            $job,
            __('Copying files into place', 'slice-backup'),
            empty($state['queue']) ? 1.0 : min(0.99, $state['done'] / $total),
            /* translators: %s: number of files. */
            sprintf(__('%s files copied', 'slice-backup'), number_format_i18n((int) $state['done']))
        );

        if (!empty($state['queue'])) {
            return false;
        }
        $job->data['summary']['files'] = $state['stats'];
        return true;
    }

    private static function finalize(Job $job): void {
        switch ($job->type) {
            case 'export':
                $file   = (string) $job->data['file'];
                $name   = (string) $job->data['name'];
                $size   = Storage::filesize($file);
                $result = array(
                    'success'       => true,
                    'bundleFile'    => $name,
                    'sizeBytes'     => $size,
                    'formattedSize' => size_format($size, 1),
                    'encrypted'     => !empty($job->options['encrypt']),
                    'uploaded'      => !empty($job->data['uploaded']),
                    'components'    => array_keys(array_filter((array) $job->options['components'])),
                    'downloadUrl'   => Storage::downloadUrl($name),
                    'deletedOldBundles' => array(),
                    'pushedTo'      => !empty($job->data['remoteName']) ? (string) $job->options['push_site'] : '',
                    'remoteRestored' => !empty($job->data['remoteResult']),
                );

                if ($job->origin === 'schedule') {
                    $result['deletedOldBundles'] = Retention::applySchedule();
                } else {
                    $result['deletedOldBundles'] = Storage::applyRetention((int) Plugin::settings()['retention_count'], $name);
                }

                if (!empty($job->options['delete_local']) && !empty($job->data['uploaded'])) {
                    Storage::delete($name);
                    $result['downloadUrl']  = '';
                    $result['localDeleted'] = true;
                }

                $job->data['result'] = $result;
                self::cleanupWork($job, false);
                if ($job->origin === 'schedule') {
                    Notifier::backupResult(true, $job, $result);
                }
                /** Fires after a bundle was created. */
                do_action('slice_backup_after_export', $result);
                break;

            case 'restore':
                $result = array(
                    'success'             => true,
                    'message'             => __('Restore finished.', 'slice-backup'),
                    'filename'            => (string) $job->options['filename'],
                    'finishedAt'          => current_time('mysql'),
                    'summary'             => $job->data['summary'],
                    'compatibilityReport' => $job->data['report'] ?? array('items' => array(), 'summary' => array()),
                    'reloginRequired'     => !empty($job->data['dbRestored']),
                );
                $job->data['result'] = $result;
                update_site_option(Plugin::OPTION_REPORT, $result);
                self::cleanupWork($job, false);
                /** Fires after a restore finished. */
                do_action('slice_backup_after_restore', $result);
                break;

            case 'upload':
                $job->data['result'] = array('success' => true, 'bundleFile' => $job->data['name'], 'uploaded' => true);
                break;

            case 'download':
            case 'pull':
                if ($job->type === 'pull') {
                    $job->data['name'] = wp_unique_filename(Storage::dir(), (string) $job->data['name']);
                }
                $final = Storage::dir() . '/' . $job->data['name'];
                if (!@rename((string) $job->data['part'], $final)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
                    throw new \RuntimeException(esc_html__('Could not save the downloaded bundle.', 'slice-backup'));
                }
                $job->data['final']  = $final;
                $job->data['result'] = array('success' => true, 'bundleFile' => $job->data['name']);
                break;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, bool>  $components
     * @param array<string, mixed> $context
     */
    private static function fileLabel(array $components, array $context): string {
        $selected = array_keys(array_filter($components));
        sort($selected);
        $label = 'custom';
        foreach (self::PRESETS as $preset => $list) {
            sort($list);
            if ($selected === $list) {
                $label = self::PRESET_FILE_LABELS[$preset];
                break;
            }
        }
        if (($context['multisite_mode'] ?? '') === 'subsite') {
            $label = 'site' . (int) $context['subsite_id'] . '-' . $label;
        }
        return $label;
    }

    /**
     * Renames the table at the start of DROP/CREATE/INSERT statements.
     */
    public static function rewriteTablePrefix(string $sql, string $from, string $to): string {
        $quoted    = preg_quote($from, '/');
        $rewritten = preg_replace('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO)(\s+)`' . $quoted . '/', '$1$2`' . $to, $sql, 1);
        if (is_string($rewritten)) {
            $sql = $rewritten;
        }
        if (strpos($sql, 'CREATE TABLE') === 0) {
            $rewritten = preg_replace('/REFERENCES `' . $quoted . '/', 'REFERENCES `' . $to, $sql);
            if (is_string($rewritten)) {
                $sql = $rewritten;
            }
        }
        return $sql;
    }

    /**
     * Renames prefix-carrying keys (wp_capabilities, wp_user_roles, ...).
     */
    private static function renamePrefixedKeys(string $from, string $to): void {
        global $wpdb;

        $quoted   = preg_quote($from, '/');
        $usermeta = $to . 'usermeta';
        $metaKeys = '(capabilities|user_level|user-settings|user-settings-time|dashboard_quick_press_last_post_id|persisted_preferences|media_library_mode)';

        $rows = $wpdb->get_results($wpdb->prepare('SELECT umeta_id, meta_key FROM %i WHERE meta_key LIKE %s', $usermeta, $wpdb->esc_like($from) . '%')); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time rename after a restore.
        foreach ((array) $rows as $row) {
            if (preg_match('/^' . $quoted . '((?:\d+_)?' . $metaKeys . ')$/', (string) $row->meta_key, $m)) {
                $wpdb->update($usermeta, array('meta_key' => $to . $m[1]), array('umeta_id' => $row->umeta_id)); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            }
        }

        foreach (DbUtil::optionsTables() as $table) {
            $options = $wpdb->get_results($wpdb->prepare('SELECT option_id, option_name FROM %i WHERE option_name LIKE %s', $table, $wpdb->esc_like($from) . '%user_roles'));
            foreach ((array) $options as $option) {
                if (preg_match('/^' . $quoted . '(\d+_)?user_roles$/', (string) $option->option_name, $m)) {
                    $wpdb->update($table, array('option_name' => $to . ($m[1] ?? '') . 'user_roles'), array('option_id' => $option->option_id));
                }
            }
        }
    }

    /**
     * Restores allow-listed root files. wp-config.php is never overwritten.
     *
     * @param string[] $notes
     * @return array{copied: int, failed: int}
     */
    private static function restoreRootFiles(string $sourceDir, string $targetDir, array &$notes): array {
        $copied = 0;
        $failed = 0;
        foreach ((array) scandir($sourceDir) as $file) {
            if (!is_string($file) || !is_file($sourceDir . '/' . $file)) {
                continue;
            }
            if ($file === 'wp-config.php') {
                $notes[] = __('wp-config.php in the bundle was not applied, because it contains the source site\'s database settings. Edit this site\'s wp-config.php by hand if you need any of its settings.', 'slice-backup');
                continue;
            }
            if (!preg_match(StreamingArchiver::ROOT_FILES_PATTERN, $file)) {
                continue;
            }
            if (@copy($sourceDir . '/' . $file, trailingslashit($targetDir) . $file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $copied++;
            } else {
                $failed++;
            }
        }
        return array('copied' => $copied, 'failed' => $failed);
    }

    /* Synchronous wrappers (WP-CLI / REST) ---------------------------- */

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws \RuntimeException When the job fails.
     */
    public function executeExport(array $options = array(), string $origin = 'rest'): array {
        $job = self::runToCompletion(self::startExport($options, $origin));
        if ($job->status !== 'done') {
            throw new \RuntimeException(esc_html($job->error));
        }
        return (array) $job->data['result'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws \RuntimeException When the job fails.
     */
    public function executeRestore(string $filename, array $options = array(), string $origin = 'rest'): array {
        $job = self::runToCompletion(self::startRestore($filename, $options, $origin));
        if ($job->status !== 'done') {
            throw new \RuntimeException(esc_html($job->error));
        }
        return (array) $job->data['result'];
    }
}
