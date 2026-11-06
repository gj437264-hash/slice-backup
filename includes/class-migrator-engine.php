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

    /** Each crash at the same position halves batch sizes and the time budget, down to 1/64. */
    private const MAX_SLOWDOWN = 6;

    /**
     * The tick running in this request, so the shutdown handler can record a crash.
     *
     * @var array{id: string, cursor: string}|null
     */
    private static $inflight = null;

    /** @var string|null Memory set aside so the shutdown handler can still run after "out of memory". */
    private static $reserve = null;

    /** @var bool */
    private static $shutdownRegistered = false;

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
        // The import, address update and plugin/theme copies go to a staging area; the live
        // site is untouched until "swap" puts everything in place at once.
        array_push($steps, 'extract', 'inspect', 'import', 'replace', 'files', 'swap', 'shield', 'finalize');

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
            // This server's settings that must survive the restore (main site on a network).
            'targetUpload'  => array(
                'upload_path'     => self::mainSiteOption('upload_path'),
                'upload_url_path' => self::mainSiteOption('upload_url_path'),
            ),
            'targetTheme'   => array((string) get_option('stylesheet'), (string) get_option('template')),
            // Staging tables are named with this prefix until the swap.
            'stagePrefix'   => DbUtil::internalPrefix('s'),
            'actions'       => array(),
            'summary'       => array('skippedEntries' => array(), 'database' => null, 'replace' => null, 'files' => array(), 'notes' => array()),
        );

        $components = array_keys(array_filter($want));
        RestoreLog::add($job, RestoreLog::INFO, sprintf('Restore of %s started on %s (PHP %s, WordPress %s, memory limit %s).', $filename, home_url(), PHP_VERSION, get_bloginfo('version'), MemoryBudget::limitLabel()), array(
            'restore'            => $components,
            'encrypted'          => $encrypted,
            'isolateConflicts'   => (bool) $job->options['isolate'],
            'oldUrl'             => $job->options['old_url'],
            'newUrl'             => $job->options['new_url'],
            'stagingTablePrefix' => $job->data['stagePrefix'],
        ));
        RestoreLog::add($job, RestoreLog::INFO, 'The live site stays as it is until every part is ready; then the database tables and plugin/theme folders are swapped in at once, and the replaced ones are kept as a safety copy for undo.');
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
            $first = false;
            try {
                $remote = Peer::request($key, 'job', (string) wp_json_encode(array('id' => $state['id'], 'token' => $state['token'])), array(), false, 90)['json'];
            } catch (PeerError $e) {
                // The other site's request can die and recover (a server error or timeout while it
                // works through a heavy step). Keep polling for a while instead of failing here.
                if ($e->status !== 0 && $e->status < 500) {
                    throw $e;
                }
                if (empty($job->data['remoteErrorSince'])) {
                    $job->data['remoteErrorSince'] = time();
                }
                if (time() - (int) $job->data['remoteErrorSince'] > 10 * MINUTE_IN_SECONDS) {
                    throw $e;
                }
                usleep((int) (1000000 * max(0.0, min(3.0, $deadline - microtime(true)))));
                $job->progress['detail'] = sprintf(
                    /* translators: %s: error message. */
                    __('The other site did not answer (%s). Still waiting for it…', 'slice-backup'),
                    Plugin::errorText($e)
                );
                return null;
            }
            unset($job->data['remoteErrorSince']);
            $p = (array) ($remote['progress'] ?? array());
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

    /**
     * Imports a bundle straight from a publicly-shared link (Google Drive, OneDrive,
     * SharePoint, Dropbox, or any other direct URL), without downloading it to the user's
     * own computer first.
     *
     * @throws \Throwable On an invalid URL or when another job is running.
     */
    public static function startLinkImport(string $url, string $origin = 'manual'): Job {
        $url = esc_url_raw(trim($url));
        if ($url === '') {
            throw new \InvalidArgumentException(esc_html__('Paste a link to the backup file first.', 'slice-backup'));
        }
        $job       = Job::create('link_import', array('resolve', 'link_download', 'finalize'), array('url' => $url), $origin);
        $job->data = array('url' => $url, 'part' => $job->workDir() . '/link.part');
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

        // Long cleanup may follow (failure or cancel): no time limit, no stop on browser abort.
        self::prepareEnvironment();

        // The previous tick died without cleaning up: a fatal error, out of memory,
        // or the host killed the request. Record it and continue in smaller pieces.
        $abandoned = $job->abandonedInflight();
        if ($abandoned !== null) {
            self::recordCrash($job, $abandoned['cursor'], $abandoned['error']);
            $job->clearInflight();
        }
        if ($job->status === 'running' && $job->cancelRequested()) {
            self::cancelNow($job);
        }
        if ($job->status !== 'running') {
            $job->unlockTick($lock);
            return $job;
        }

        RestoreGuard::heartbeat($job);

        $factor = 1 / (2 ** self::slowdown($job));
        MemoryBudget::setFactor($factor);
        $budget   = max(3.0, $budget * $factor);
        $deadline = microtime(true) + $budget;

        self::beginInflight($job);
        try {
            while ($job->status === 'running' && microtime(true) < $deadline) {
                // Cancel requests are handled here, by the request holding the lock, at a safe point.
                if ($job->cancelRequested()) {
                    self::cancelNow($job);
                    break;
                }
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

                // This step ran without crashing: earlier crashes here were not deterministic.
                unset($job->recovery['crashes'], $job->recovery['cursor']);

                if ($done) {
                    unset($job->state[$step]);
                    $since = (int) ($job->data['stepSince'] ?? $job->created);
                    RestoreLog::add($job, RestoreLog::INFO, sprintf('Step "%s" finished in %s (peak memory %s).', self::stepLabel($step), human_time_diff($since, max($since + 1, time())), size_format((float) memory_get_peak_usage(true))));
                    $job->data['stepSince'] = time();
                    $job->stepIndex++;
                    if ($job->stepIndex >= count($job->steps)) {
                        $job->finish();
                        break;
                    }
                    // A new step starts at its own speed.
                    MemoryBudget::setFactor(1 / (2 ** self::slowdown($job)));
                }
                $job->save();
                self::moveInflight($job);
            }
        } catch (\Throwable $e) {
            if ($job->cancelRequested()) {
                self::cancelNow($job);
            } else {
                self::onFailure($job, Plugin::errorText($e));
            }
        } finally {
            self::endInflight($job);
            $job->unlockTick($lock);
        }

        if ($job->status === 'running') {
            self::scheduleBackgroundTick($job);
        }
        return $job;
    }

    /* ------------------------------------------------------------------ */
    /* Crash recovery                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Slow-down level of the current step (0 = full speed).
     */
    private static function slowdown(Job $job): int {
        $levels = (array) ($job->recovery['levels'] ?? array());
        return max(0, min(self::MAX_SLOWDOWN, (int) ($levels[$job->step()] ?? 0)));
    }

    /**
     * Identifies the saved position of the job: a crash at the same cursor means no progress.
     */
    private static function cursor(Job $job): string {
        return md5($job->stepIndex . '|' . (string) wp_json_encode($job->state[$job->step()] ?? null));
    }

    private static function beginInflight(Job $job): void {
        if (!self::$shutdownRegistered) {
            register_shutdown_function(array(__CLASS__, 'onShutdown'));
            self::$shutdownRegistered = true;
        }
        self::$reserve  = str_repeat(' ', MB_IN_BYTES);
        self::$inflight = array('id' => $job->id, 'cursor' => self::cursor($job), 'marker' => $job->workDir() . '/inflight');
        $job->markInflight(self::$inflight['cursor']);
    }

    private static function moveInflight(Job $job): void {
        if (self::$inflight !== null && $job->status === 'running') {
            self::$inflight['cursor'] = self::cursor($job);
            $job->markInflight(self::$inflight['cursor']);
        }
    }

    private static function endInflight(Job $job): void {
        self::$inflight = null;
        self::$reserve  = null;
        $job->clearInflight();
    }

    /**
     * Runs when PHP stops. If a tick was still running, the request died mid-tick (fatal
     * error, out of memory, or exit). This handler may have almost no memory left, so it
     * only adds the error to the in-flight marker and queues a retry; the next tick, with
     * fresh memory, records the crash and slows down.
     *
     * @internal Registered with register_shutdown_function().
     */
    public static function onShutdown(): void {
        if (self::$inflight === null) {
            return;
        }
        self::$reserve  = null;
        $info           = self::$inflight;
        self::$inflight = null;

        $error   = error_get_last();
        $fatal   = is_array($error) && in_array((int) $error['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true);
        $message = $fatal ? str_replace(array("\r", "\n"), ' ', substr((string) $error['message'], 0, 400)) : 'exit';

        file_put_contents((string) $info['marker'], $info['cursor'] . "\n" . $message); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if (function_exists('wp_schedule_single_event') && !wp_next_scheduled(self::TICK_HOOK, array((string) $info['id']))) {
            wp_schedule_single_event(time() + 15, self::TICK_HOOK, array((string) $info['id']));
        }
    }

    /**
     * Records a crashed tick: slows the step down, or stops the job when even the
     * smallest batches crash at the same position.
     */
    private static function recordCrash(Job $job, string $cursor, string $error): void {
        $step     = $job->step();
        $recovery = $job->recovery;
        $levels   = (array) ($recovery['levels'] ?? array());
        $level    = (int) ($levels[$step] ?? 0);
        $same     = ($recovery['cursor'] ?? '') === $cursor;
        $crashes  = $same ? (int) ($recovery['crashes'] ?? 0) + 1 : 1;

        $recovery['cursor']    = $cursor;
        $recovery['crashes']   = $crashes;
        $recovery['total']     = (int) ($recovery['total'] ?? 0) + 1;
        $recovery['lastError'] = substr($error, 0, 500);
        $reason                = self::crashReason($error);

        if ($level >= self::MAX_SLOWDOWN && $same && $crashes >= 2) {
            $job->recovery = $recovery;
            self::onFailure($job, self::crashFailureMessage($job, $error, $reason));
            return;
        }

        $levels[$step]      = min(self::MAX_SLOWDOWN, $level + 1);
        $recovery['levels'] = $levels;

        // The import replays statements after the last saved position. Mark that, and restart a
        // table without a primary key from its beginning once (its rows cannot reject duplicates).
        if ($step === 'import' && !empty($job->state['import'])) {
            $import               = (array) $job->state['import'];
            $import['afterCrash'] = 1;
            if (!empty($import['keyless']) && isset($import['tableStart']) && (string) ($import['rewound'] ?? '') !== (string) $import['tableStart']) {
                $import['pos']     = (float) $import['tableStart'];
                $import['rewound'] = (float) $import['tableStart'];
            }
            $job->state['import'] = $import;
        }
        $recovery['note']   = sprintf(
            /* translators: %s: reason, for example "the server ran out of memory (limit 128M)". */
            __('Recovered from a server stop (%s). Continuing in smaller pieces.', 'slice-backup'),
            $reason
        );
        $job->recovery = $recovery;
        RestoreLog::add(
            $job,
            RestoreLog::WARNING,
            sprintf(
                /* translators: 1: step name, 2: reason. */
                __('The server stopped the request during "%1$s" (%2$s). The step was resumed from its last saved position with smaller batches.', 'slice-backup'),
                self::stepLabel($step),
                $reason
            ),
            array('crashesHere' => $crashes, 'slowdownLevel' => $levels[$step], 'phpError' => $error),
            $crashes > 1 ? __('If this keeps happening on large sites, raise the PHP memory limit or use WP-CLI for restores.', 'slice-backup') : ''
        );
        $job->save();
    }

    private static function crashReason(string $error): string {
        if (stripos($error, 'Allowed memory size') !== false || stripos($error, 'Out of memory') !== false) {
            return sprintf(
                /* translators: %s: PHP memory limit, for example 128M. */
                __('the server ran out of memory, limit %s', 'slice-backup'),
                MemoryBudget::limitLabel()
            );
        }
        if (stripos($error, 'Maximum execution time') !== false) {
            return __('the server time limit was reached', 'slice-backup');
        }
        if ($error === '' || $error === 'exit') {
            return sprintf(
                /* translators: %s: PHP memory limit, for example 128M. */
                __('the server stopped the request, usually because of the memory limit (%s) or a time limit', 'slice-backup'),
                MemoryBudget::limitLabel()
            );
        }
        return wp_strip_all_tags(substr($error, 0, 300));
    }

    /**
     * Readable name of a job step, for messages.
     */
    private static function stepLabel(string $step): string {
        $labels = array(
            'dump'          => __('Exporting the database', 'slice-backup'),
            'scan'          => __('Finding files', 'slice-backup'),
            'archive'       => __('Compressing files', 'slice-backup'),
            'encrypt'       => __('Encrypting the bundle', 'slice-backup'),
            'decrypt'       => __('Decrypting the bundle', 'slice-backup'),
            'extract'       => __('Unpacking the bundle', 'slice-backup'),
            'inspect'       => __('Checking plugins and themes', 'slice-backup'),
            'import'        => __('Importing the database', 'slice-backup'),
            'replace'       => __('Updating addresses in the database', 'slice-backup'),
            'files'         => __('Copying files into place', 'slice-backup'),
            'swap'          => __('Switching the site over to the restored version', 'slice-backup'),
            'shield'        => __('Protecting the site from incompatible code', 'slice-backup'),
            'upload'        => __('Uploading to cloud storage', 'slice-backup'),
            'download'      => __('Downloading from cloud storage', 'slice-backup'),
            'push'          => __('Sending to the other site', 'slice-backup'),
            'remote_export' => __('Creating the backup on the other site', 'slice-backup'),
            'pull_download' => __('Downloading from the other site', 'slice-backup'),
            'resolve'       => __('Reading the link', 'slice-backup'),
            'link_download' => __('Downloading from the link', 'slice-backup'),
            'finalize'      => __('Finishing', 'slice-backup'),
        );
        return $labels[$step] ?? $step;
    }

    private static function crashFailureMessage(Job $job, string $error, string $reason): string {
        $label = self::stepLabel($job->step());
        if (stripos($error, 'Allowed memory size') !== false || stripos($error, 'Out of memory') !== false) {
            $advice = __('Raise the PHP memory limit (for example define( \'WP_MEMORY_LIMIT\', \'512M\' ); in wp-config.php, or ask your host), or run it with WP-CLI, then start again.', 'slice-backup');
        } elseif ($error === '' || $error === 'exit') {
            $advice = __('Ask your host to raise the PHP memory limit (WP_MEMORY_LIMIT) or time limit, or run it with WP-CLI, then start again.', 'slice-backup');
        } elseif (stripos($error, 'Maximum execution time') !== false) {
            $advice = __('Ask your host to raise the PHP time limit, or run it with WP-CLI, then start again.', 'slice-backup');
        } else {
            $advice = __('Run it with WP-CLI to see the full error, or send this message to your host.', 'slice-backup');
        }
        return sprintf(
            /* translators: 1: step name, 2: reason, 3: advice. */
            __('Stopped at "%1$s": %2$s, even with the smallest batches. %3$s', 'slice-backup'),
            $label,
            $reason,
            $advice
        );
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

        // The URL saved when the job started: after a database import, admin_url() points at the source site.
        $ajaxUrl = (string) ($job->options['_ajaxUrl'] ?? '');
        wp_remote_post(
            $ajaxUrl !== '' ? $ajaxUrl : admin_url('admin-ajax.php'),
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
    public static function cancel(Job $job): bool {
        // Never clean up while another request is still writing: if a tick holds the lock,
        // it handles the cancellation itself at its next safe point.
        $lock = $job->lockTick();
        if ($lock === null) {
            $job->requestCancel();
            return false;
        }
        try {
            $fresh = Job::load($job->id);
            $job   = $fresh ?? $job;
            if ($job->status === 'running') {
                self::prepareEnvironment();
                self::cancelNow($job);
            }
        } finally {
            $job->unlockTick($lock);
        }
        return true;
    }

    /**
     * Cancels a job. Call only while holding its tick lock.
     */
    private static function cancelNow(Job $job): void {
        if (in_array($job->type, array('export', 'upload'), true) && isset($job->state['upload'])) {
            Cloud::abortUpload((array) $job->state['upload']);
        }
        RestoreLog::add($job, RestoreLog::WARNING, __('Cancelled at the user\'s request.', 'slice-backup'));
        self::endRestoreSafely($job);
        $job->cancel();
        RestoreLog::persist($job);
        $job->save();
        self::cleanupWork($job, true);
    }

    /**
     * Ends a job that stopped making progress, safely (a restore leaves a site that boots).
     * Returns false when another request is still working on it.
     */
    public static function endStalled(Job $job, string $message): bool {
        $lock = $job->lockTick();
        if ($lock === null) {
            return false;
        }
        try {
            $fresh = Job::load($job->id);
            $job   = $fresh ?? $job;
            if ($job->status === 'running') {
                self::prepareEnvironment();
                self::onFailure($job, $message);
            }
        } finally {
            $job->unlockTick($lock);
        }
        return true;
    }

    /**
     * Runs early on every request while a restore guard is installed: keeps a restore
     * moving when nobody has the admin page open, and ends one that stopped making
     * progress for a long time, so the site is never left in maintenance or half-restored.
     */
    public static function watchRestore(): void {
        if (!RestoreGuard::isInstalled()) {
            return;
        }
        $job = Job::active();
        if ($job === null || $job->type !== 'restore') {
            // The restore is over but its guard is still there.
            RestoreGuard::remove();
            return;
        }
        $idle = RestoreGuard::idleSeconds($job);
        if ($idle === null || $idle < RestoreGuard::KICK_AFTER_SECONDS) {
            return;
        }
        if ($idle > RestoreGuard::MAX_IDLE_SECONDS) {
            self::endStalled($job, __('The restore stopped making progress for two hours and was ended. Plugins and themes that might not load were switched off so the site works; run the restore again to finish it.', 'slice-backup'));
            return;
        }
        // Nudge it at most once a minute.
        $kick = $job->workDir() . '/kick';
        if (!is_file($kick) || (time() - (int) filemtime($kick)) > MINUTE_IN_SECONDS) {
            @touch($kick); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            self::scheduleBackgroundTick($job);
        }
    }

    private static function onFailure(Job $job, string $message): void {
        if (isset($job->state['upload'])) {
            Cloud::abortUpload((array) $job->state['upload']);
        }
        RestoreLog::add($job, RestoreLog::ERROR, $message, array('step' => $job->step()));
        self::endRestoreSafely($job);
        $job->fail($message);
        RestoreLog::persist($job);
        $job->save();
        self::cleanupWork($job, true);

        if ($job->origin === 'schedule') {
            Notifier::backupResult(false, $job, null);
        }
    }

    /**
     * A restore that stops early (failure or cancel) must leave a site that works.
     *
     * Before the swap the live site was never changed: the staging tables and folders are
     * simply deleted (an automatic rollback). After the swap the restored site is live:
     * detected conflicts are switched off, this server's settings are kept, and the safety
     * copy stays available for undo.
     */
    private static function endRestoreSafely(Job $job): void {
        if ($job->type !== 'restore') {
            return;
        }
        try {
            RestoreGuard::disableInThisRequest();
            if (empty($job->data['swapped'])) {
                self::discardStaging($job);
                RestoreLog::add($job, RestoreLog::ACTION, __('The restore stopped before the site was switched over, so the live database, plugins and themes were left exactly as they were. The staging copies were deleted.', 'slice-backup'));
                $want    = (array) ($job->options['want'] ?? array());
                $inPlace = array_intersect(array('uploads', 'languages', 'wp_core', 'root_files'), array_keys(array_filter($want)));
                if (!empty($inPlace) && $job->stepIndex >= (int) array_search('files', $job->steps, true)) {
                    RestoreLog::add(
                        $job,
                        RestoreLog::WARNING,
                        sprintf(
                            /* translators: %s: list of parts, for example "uploads, languages". */
                            __('Files of these parts are copied straight into place and may have been partly restored: %s.', 'slice-backup'),
                            implode(', ', $inPlace)
                        ),
                        array(),
                        __('Run the restore again to finish them, or restore those folders from another backup.', 'slice-backup')
                    );
                }
            } else {
                $inspector = new CompatibilityInspector();
                $inspector->setLogger(self::logger($job));
                if (!empty($job->options['isolate']) && !empty($job->data['report']['items'])) {
                    $plugins = array();
                    $themes  = array();
                    foreach ((array) $job->data['report']['items'] as $item) {
                        if (($item['status'] ?? '') === 'conflict') {
                            if (($item['type'] ?? '') === 'plugin') {
                                $plugins[] = (string) $item['slug'];
                            } elseif (($item['type'] ?? '') === 'theme') {
                                $themes[] = (string) $item['slug'];
                            }
                        }
                    }
                    $self = basename(untrailingslashit(SLICE_BACKUP_PATH));
                    $inspector->safeBoot(array_values(array_diff(array_unique($plugins), array($self))), array_values(array_unique($themes)));
                }
                self::keepTargetSettings($job);
                RestoreLog::add(
                    $job,
                    RestoreLog::WARNING,
                    __('The restore stopped after the site was switched over to the restored version.', 'slice-backup'),
                    array(),
                    __('Check the site. To go back to the site as it was before this restore, use "Undo this restore" on the Restore tab.', 'slice-backup')
                );
            }
        } catch (\Throwable $e) {
            RestoreLog::add($job, RestoreLog::ERROR, Plugin::errorText($e));
            $job->warnings[] = Plugin::errorText($e);
        }
        RestoreGuard::remove($job);
    }

    /**
     * Logger callback for helpers that report what they do.
     */
    private static function logger(Job $job): callable {
        return static function (string $level, string $message, array $context = array(), string $followUp = '') use ($job): void {
            RestoreLog::add($job, $level, $message, $context, $followUp);
        };
    }

    /**
     * Deletes a restore's staging tables and folders, and puts back any folder that was
     * already swapped when the restore stopped.
     */
    private static function discardStaging(Job $job): void {
        $swap = (array) ($job->state['swap'] ?? array());
        self::revertFolderSwaps((array) ($swap['folders'] ?? array()), (int) ($swap['done'] ?? 0));

        $prefix = (string) ($job->data['stagePrefix'] ?? '');
        if ($prefix !== '' && DbUtil::isInternalTable($prefix . 'x')) {
            RestoreRollback::dropTables(DbUtil::tables($prefix));
        }
        foreach (self::stagingRoots() as $root) {
            RestoreRollback::delete($root . '/' . RestoreRollback::STAGING_DIR);
        }
    }

    /**
     * Folders (plugins and themes) that are restored by swapping whole folders.
     *
     * @return array<string, string> Component => root folder.
     */
    private static function stagingRoots(): array {
        return array(
            'plugins' => wp_normalize_path(untrailingslashit(WP_PLUGIN_DIR)),
            'themes'  => wp_normalize_path(untrailingslashit(get_theme_root())),
        );
    }

    /**
     * Undoes folder swaps (also one that stopped half-way between its two moves).
     * Each swap is: live -> old, then staged -> live. Folders not reached are left alone.
     *
     * @param array<int, array<string, mixed>> $folders
     */
    private static function revertFolderSwaps(array $folders, int $done): void {
        $last = min(count($folders) - 1, $done);
        for ($i = $last; $i >= 0; $i--) {
            $live   = (string) $folders[$i]['live'];
            $staged = (string) $folders[$i]['staged'];
            $old    = (string) $folders[$i]['old'];
            if (!file_exists($staged) && file_exists($live)) {
                RestoreRollback::move($live, $staged);
            }
            if (file_exists($old) && !file_exists($live)) {
                RestoreRollback::move($old, $live);
            }
        }
    }

    /**
     * An option of the main site, read straight from the database (no filters or cache).
     */
    private static function mainSiteOption(string $name): string {
        global $wpdb;
        $table = is_multisite() ? $wpdb->get_blog_prefix(get_main_site_id()) . 'options' : $wpdb->options;
        $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $name));
        return is_string($value) ? $value : '';
    }

    /**
     * Writes this server's upload folder settings back into the main site's options table.
     * Done straight after the import touches that table, so the backup folder (and the
     * running job inside it) never moves, with or without the restore guard.
     *
     * @param array<string, string> $values
     */
    private static function pinUploadSettings(array $values): void {
        global $wpdb;
        $table = is_multisite() ? $wpdb->get_blog_prefix(get_main_site_id()) . 'options' : $wpdb->options;
        foreach ($values as $name => $value) {
            $wpdb->query($wpdb->prepare('UPDATE %i SET option_value = %s WHERE option_name = %s', $table, (string) $value, (string) $name));
        }
        wp_cache_delete('alloptions', 'options');
        foreach (array_keys($values) as $name) {
            wp_cache_delete((string) $name, 'options');
        }
    }

    /**
     * After a restore: keep this server's upload folder settings and keep Slice Backup active.
     */
    private static function keepTargetSettings(Job $job): void {
        // Real settings, real writes: the guard's filters must not hide or block them.
        RestoreGuard::disableInThisRequest();
        wp_cache_flush();

        if (isset($job->data['targetUpload'])) {
            self::pinUploadSettings((array) $job->data['targetUpload']);
            if (empty($job->data['uploadKeptLogged'])) {
                $job->data['uploadKeptLogged'] = true;
                RestoreLog::add($job, RestoreLog::INFO, 'This server\'s upload folder settings were kept (the restored ones belong to the source server).', (array) $job->data['targetUpload']);
            }
        }

        $basename = plugin_basename(SLICE_BACKUP_FILE);
        $added    = false;
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', array());
            $site    = (array) get_option('active_plugins', array());
            if (!isset($network[$basename]) && !in_array($basename, $site, true)) {
                $network[$basename] = time();
                update_site_option('active_sitewide_plugins', $network);
                $added = true;
            }
        } else {
            $active = array_values(array_filter((array) get_option('active_plugins', array()), 'is_string'));
            if (!in_array($basename, $active, true)) {
                $active[] = $basename;
                sort($active);
                update_option('active_plugins', $active);
                $added = true;
            }
        }
        if ($added) {
            RestoreLog::add($job, RestoreLog::ACTION, __('Slice Backup was not active in the restored site and was activated, so this report and the undo option stay available.', 'slice-backup'));
        }
        wp_cache_flush();
    }

    /**
     * Moves the staged must-use plugins into place (the last thing a restore does,
     * so they never load while the restore still needs to run).
     */
    private static function placeMuPlugins(Job $job): void {
        $staging = $job->workDir() . '/mu-staging';
        if (!is_dir($staging)) {
            return;
        }
        $target = wp_normalize_path(untrailingslashit(defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins'));
        wp_mkdir_p($target);

        // Must-use plugins cannot be switched off: leave out the ones that would fail on this PHP.
        $skip = (new CompatibilityInspector())->muPluginConflicts($staging);
        foreach ($skip as $entry => $issue) {
            $job->warnings[] = sprintf(
                /* translators: 1: file or folder name, 2: problem. */
                __('The must-use plugin "%1$s" was not restored because it would stop the site from loading on this server: %2$s', 'slice-backup'),
                $entry,
                $issue
            );
            $job->data['report']['items'][] = array(
                'name'           => $entry,
                'slug'           => $entry,
                'type'           => 'mu-plugin',
                'status'         => 'conflict',
                'issue'          => $issue,
                'recommendation' => __('Update it to a version that supports this PHP version, then copy it into wp-content/mu-plugins.', 'slice-backup'),
                'file'           => $entry,
                'actionTaken'    => __('Not restored, so the site can load.', 'slice-backup'),
            );
            $job->data['report']['summary']['conflictCount']  = (int) ($job->data['report']['summary']['conflictCount'] ?? 0) + 1;
            $job->data['report']['summary']['totalInspected'] = (int) ($job->data['report']['summary']['totalInspected'] ?? 0) + 1;
        }

        $copied = 0;
        $failed = 0;
        $items  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $relative = substr(wp_normalize_path($item->getPathname()), strlen(wp_normalize_path($staging)) + 1);
            $topLevel = strtok($relative, '/');
            if ($relative === RestoreGuard::FILE || $relative === RestoreGuard::CONFIG_FILE || isset($skip[(string) $topLevel])) {
                continue;
            }
            $dst = $target . '/' . $relative;
            if ($item->isDir()) {
                wp_mkdir_p($dst);
            } elseif (@copy($item->getPathname(), $dst)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                $copied++;
            } else {
                $failed++;
            }
        }
        $job->data['summary']['files']['mu_plugins'] = array('copied' => $copied, 'failed' => $failed);
        if (!empty($skip)) {
            RestoreLog::add(
                $job,
                RestoreLog::WARNING,
                sprintf(
                    /* translators: %d: number of must-use plugins. */
                    __('%d must-use plugin(s) were left out because they would crash on this server.', 'slice-backup'),
                    count($skip)
                ),
                array('files' => array_keys($skip)),
                __('Update them to a version that supports this PHP version, then copy them into wp-content/mu-plugins by hand.', 'slice-backup')
            );
        }
        if ($copied > 0 || $failed > 0) {
            RestoreLog::add($job, RestoreLog::INFO, sprintf('Must-use plugin files restored: %d copied, %d failed.', $copied, $failed));
        }
    }

    /**
     * Deletes temporary files of a job (keeps state.json).
     */
    private static function cleanupWork(Job $job, bool $failed): void {
        $dir = $job->workDir();
        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || in_array($entry, array('.', '..', 'state.json', 'tick.lock', 'job.log'), true)) {
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
        if ($failed && in_array($job->type, array('download', 'pull', 'link_import'), true) && !empty($job->data['final']) && is_file((string) $job->data['final'])) {
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

            case 'resolve':
                $resolved = LinkImporter::resolve((string) $job->data['url']);
                $probe    = LinkImporter::probe($resolved);
                $job->data['resolved'] = $resolved;
                $job->data['name']     = wp_unique_filename(Storage::dir(), $probe['name']);
                $job->data['remoteSize'] = $probe['size'];
                $job->data['linkState']  = array(
                    'size'    => $probe['size'],
                    'offset'  => 0.0,
                    'rangeOk' => $probe['rangeOk'],
                );
                self::setProgress($job, __('Reading the link', 'slice-backup'), 1.0, size_format($probe['size'], 1));
                return true;

            case 'link_download':
                $part = (string) $job->data['part'];
                if (empty($state)) {
                    file_put_contents($part, ''); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                    $state = (array) $job->data['linkState'];
                } else {
                    self::rewind($part, $state);
                    $state['offset'] = min((float) $state['offset'], Storage::filesize($part));
                }
                $done = LinkImporter::downloadStep((array) $job->data['resolved'], $part, $state, $deadline);
                self::remember($part, $state);
                $job->data['linkState'] = $state;
                $size = max(1.0, (float) $state['size']);
                self::setProgress($job, __('Downloading from the link', 'slice-backup'), (float) $state['offset'] / $size, size_format((float) $state['offset'], 1) . ' / ' . size_format($size, 1));
                if (!$done) {
                    return false;
                }

                // Trust nothing from the link: open it as a real bundle before it is kept.
                if (Crypto::isEncryptedFile($part)) {
                    if (substr((string) $job->data['name'], -8) !== '.slicebk') {
                        $job->data['name'] = wp_unique_filename(Storage::dir(), preg_replace('/\.[^.]+$/', '', (string) $job->data['name']) . '.slicebk');
                    }
                    return true;
                }
                $zip = new \ZipArchive();
                $opened = $zip->open($part, \ZipArchive::CHECKCONS);
                if ($opened === true) {
                    $zip->close();
                    return true;
                }
                wp_delete_file($part);
                throw new \RuntimeException(esc_html__('The downloaded file is not a valid Slice Backup bundle (it may be damaged, or the link did not point at a bundle). Nothing was imported.', 'slice-backup'));

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

            case 'swap':
                return self::swapStep($job, $state, $deadline);

            case 'shield':
                $report    = (array) $job->data['report'];
                $inspector = new CompatibilityInspector();
                $inspector->setLogger(self::logger($job));
                RestoreGuard::disableInThisRequest();
                $inspector->applyShield($report, !empty($job->options['isolate']));
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

        // Each tick is a new database session: re-apply the dump's session settings.
        if ((float) $state['pos'] > 0) {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
            $wpdb->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        }

        // Everything goes into staging tables; the live tables are swapped only at the end.
        $stagePrefix = (string) $job->data['stagePrefix'];
        $lastSave    = microtime(true);

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
            $ends   = self::endsStatement($line);
            $line   = '';
            if (!$ends) {
                continue;
            }

            // Hand the statement over without copying it (rows can be many megabytes).
            $sql    = $query;
            $query  = '';
            $length = strlen($sql);

            $state['pos'] = (float) $state['pos'] + $length;

            $sql = self::rewriteTablePrefix($sql, $sourcePrefix, $stagePrefix);
            if (!preg_match('/^\s*SET\s/i', $sql)) {
                // Only statements on staging tables may run: nothing in the bundle can touch a live table.
                $table = preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|UPDATE)\s+`([^`]+)`/', $sql, $m) ? $m[1] : '';
                if ($table === '' || strpos($table, $stagePrefix) !== 0) {
                    $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
                    if ((int) $state['skipped'] <= 20) {
                        RestoreLog::add($job, RestoreLog::WARNING, __('A statement in the database file was skipped because it does not write a WordPress table of the bundle.', 'slice-backup'), array('statement' => substr($sql, 0, 120)));
                    }
                    unset($sql);
                    continue;
                }
                if (strncmp($sql, 'CREATE TABLE', 12) === 0 && strlen($table) > 64) {
                    throw new \RuntimeException(esc_html(sprintf(
                        /* translators: %s: table name. */
                        __('The table name %s is too long to restore safely (MySQL allows 64 characters). Nothing on the live site was changed.', 'slice-backup'),
                        substr($table, strlen($stagePrefix))
                    )));
                }
            }
            if (!empty($state['fixCollation']) && strncmp($sql, 'CREATE TABLE', 12) === 0 && strpos($sql, 'utf8mb4_0900_') !== false) {
                $sql = (string) preg_replace('/utf8mb4_0900_[a-z_]+/', 'utf8mb4_unicode_520_ci', $sql);
            }
            $isDdl = strncmp($sql, 'DROP', 4) === 0 || strncmp($sql, 'CREATE', 6) === 0;
            if (strncmp($sql, 'DROP TABLE', 10) === 0) {
                // Start of a table: where a replay after a crash can safely restart it.
                $state['tableStart'] = (float) $state['pos'] - $length;
                unset($state['keyless'], $state['afterCrash']);
            } elseif (strncmp($sql, 'CREATE TABLE', 12) === 0) {
                $state['keyless'] = stripos($sql, 'PRIMARY KEY') === false && stripos($sql, 'UNIQUE') === false;
            }

            $wpdb->check_current_query = false;
            if ($wpdb->query($sql) === false) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Restoring runs the statements of the bundle's own SQL dump (values escaped when it was written). Only administrators can start a restore.
                $error = (string) $wpdb->last_error;
                if (!empty($state['afterCrash']) && stripos($error, 'Duplicate entry') !== false) {
                    // Rows already written before the crash: expected, not an error.
                    $state['replayed'] = (int) ($state['replayed'] ?? 0) + 1;
                } else {
                    $state['errors']++;
                    if (count($state['errorSamples']) < 5) {
                        $state['errorSamples'][] = substr($error, 0, 300);
                    }
                    if ($state['errors'] <= 100) {
                        RestoreLog::add($job, RestoreLog::WARNING, __('A database statement failed and was skipped; the import continued.', 'slice-backup'), array('error' => substr($error, 0, 300), 'statement' => substr($sql, 0, 160)));
                    }
                }
            }
            $state['statements']++;
            $ran++;
            unset($sql);
            DbUtil::releaseMemory();

            // Save the position often, so a crash replays at most a couple of seconds of statements.
            if ($isDdl || microtime(true) - $lastSave >= 2.0) {
                self::checkpoint($job, 'import', $state);
                $lastSave = microtime(true);
                if ($job->cancelRequested()) {
                    break;
                }
            }
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
            self::renamePrefixedKeys($sourcePrefix, $targetPrefix, $stagePrefix);
            $job->data['summary']['notes'][] = sprintf(
                /* translators: 1: source table prefix, 2: this site's table prefix. */
                __('Table prefix converted from "%1$s" to "%2$s".', 'slice-backup'),
                $sourcePrefix,
                $targetPrefix
            );
            RestoreLog::add($job, RestoreLog::INFO, sprintf('Table prefix converted from "%s" to "%s" (including user role and capability keys).', $sourcePrefix, $targetPrefix));
        }

        $job->data['dbStaged']            = true;
        $job->data['summary']['database'] = array(
            'statements'   => (int) $state['statements'],
            'errors'       => (int) $state['errors'],
            'errorSamples' => (array) $state['errorSamples'],
        );
        RestoreLog::add($job, RestoreLog::INFO, sprintf('Database imported into staging tables: %d statements, %d errors, %d replayed after a server stop, %d skipped.', (int) $state['statements'], (int) $state['errors'], (int) ($state['replayed'] ?? 0), (int) ($state['skipped'] ?? 0)));
        if ((int) $state['errors'] > 0) {
            RestoreLog::add(
                $job,
                RestoreLog::WARNING,
                sprintf(
                    /* translators: %d: number of errors. */
                    _n('%d database statement failed during the import; the rest was restored.', '%d database statements failed during the import; the rest was restored.', (int) $state['errors'], 'slice-backup'),
                    (int) $state['errors']
                ),
                array('firstErrors' => (array) $state['errorSamples']),
                __('Check the listed errors in the full log; they usually concern a plugin table that this server\'s database version does not support.', 'slice-backup')
            );
        }
        return true;
    }

    /**
     * Saves a step's position mid-step, so a crash does not repeat work already done.
     *
     * @param array<string, mixed> $state
     */
    private static function checkpoint(Job $job, string $step, array $state): void {
        $job->state[$step] = $state;
        $job->save();
        self::moveInflight($job);
    }

    /**
     * Whether a line ends an SQL statement (last non-whitespace character is ";"),
     * checked without copying the line.
     */
    private static function endsStatement(string $line): bool {
        for ($i = strlen($line) - 1; $i >= 0; $i--) {
            $char = $line[$i];
            if ($char !== "\n" && $char !== "\r" && $char !== ' ' && $char !== "\t") {
                return $char === ';';
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function replaceStep(Job $job, array &$state, float $deadline): bool {
        if (empty($job->data['dbStaged'])) {
            return true;
        }

        $stagePrefix = (string) $job->data['stagePrefix'];
        $replacer    = new RecursiveSearchReplace();
        if (empty($state)) {
            // The staging tables are updated, before anything goes live.
            $state = $replacer->begin(self::urlPairs($job), DbUtil::tables($stagePrefix));
        }
        // Save after every window: replacing the same rows twice can corrupt addresses
        // (for example when the new address contains the old one).
        $replacer->setCheckpoint(
            static function (array $windowState) use ($job): void {
                self::checkpoint($job, 'replace', $windowState);
            }
        );
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
                $replacer->replaceMultisiteNetworkDomain($oldDomain, $newDomain, $stagePrefix);
                $stats['networkDomain'] = array('from' => $oldDomain, 'to' => $newDomain);
                RestoreLog::add($job, RestoreLog::INFO, sprintf('Network domain changed from %s to %s.', $oldDomain, $newDomain));
            }
        }

        // Permalink rules are rebuilt by WordPress for the new address.
        global $wpdb;
        foreach (DbUtil::optionsTables($stagePrefix) as $table) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $table, 'rewrite_rules'));
        }
        $job->data['summary']['replace'] = $stats;
        if ($stats['from'] !== '' && $stats['from'] !== $stats['to']) {
            RestoreLog::add($job, RestoreLog::INFO, sprintf('Addresses changed from %s to %s: %d rows updated in %d tables, %d malformed serialized values left unchanged, %d errors.', $stats['from'], $stats['to'], (int) $stats['rowsUpdated'], (int) $stats['tables'], (int) $stats['skippedSerialized'], (int) $stats['errors']));
        }
        if (!empty($stats['skippedSerialized'])) {
            RestoreLog::add(
                $job,
                RestoreLog::WARNING,
                sprintf(
                    /* translators: %d: number of values. */
                    __('%d stored values were damaged (malformed serialized data) and could not have their addresses updated.', 'slice-backup'),
                    (int) $stats['skippedSerialized']
                ),
                array(),
                __('These values were already damaged on the source site. If a plugin setting looks wrong, re-save that plugin\'s settings.', 'slice-backup')
            );
        }
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
                // Plugins and themes are copied into staging folders and swapped in whole at the end,
                // so a plugin is never half there while the site runs.
                'plugins'    => array('plugins', self::stagingRoots()['plugins'] . '/' . RestoreRollback::STAGING_DIR),
                'themes'     => array('themes', self::stagingRoots()['themes'] . '/' . RestoreRollback::STAGING_DIR),
                // Must-use plugins load on every request and cannot be held back by the restore
                // guard, so they are staged here and moved into place when the restore ends.
                'mu_plugins' => array('mu-plugins', $job->workDir() . '/mu-staging'),
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
            // Leftovers of an earlier, interrupted restore.
            foreach (self::stagingRoots() as $root) {
                RestoreRollback::delete($root . '/' . RestoreRollback::STAGING_DIR);
            }

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
            // Remember which plugin and theme folders are being overwritten: if the restore stops
            // early, exactly these may be incomplete and must not be loaded.
            if (empty($item['top']) && in_array($key, array('plugins', 'themes'), true)) {
                $root = $extract . '/' . $key . '/';
                $slug = strtok(substr((string) $item['src'], strlen($root)), '/');
                if (is_string($slug) && $slug !== '') {
                    $state['started'][$key][$slug] = true;
                }
            }

            $count = count($entries);
            for ($i = (int) $item['i']; $i < $count; $i++) {
                if ($copies > 0 && (microtime(true) >= $deadline || ($copies % 50 === 0 && $job->cancelRequested()))) {
                    $state['queue'][0]['i'] = $i;
                    break 2;
                }
                $copies++;
                $name = $entries[$i];
                // Never overwrite this plugin while it is running.
                if (!empty($item['top']) && $key === 'plugins' && $name === $self) {
                    continue;
                }
                if (!empty($item['top']) && $key === 'mu_plugins' && in_array($name, array(RestoreGuard::FILE, RestoreGuard::CONFIG_FILE), true)) {
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
                    if ($state['stats'][$key]['failed'] <= 50) {
                        RestoreLog::add($job, RestoreLog::WARNING, __('A file could not be copied; the restore continued.', 'slice-backup'), array('file' => substr($dst, strlen(wp_normalize_path(ABSPATH)))));
                    }
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
        foreach ((array) $state['stats'] as $component => $stats) {
            RestoreLog::add($job, RestoreLog::INFO, sprintf('Files of "%s": %d copied, %d failed%s.', $component, (int) ($stats['copied'] ?? 0), (int) ($stats['failed'] ?? 0), in_array($component, array('plugins', 'themes', 'mu_plugins'), true) ? ' (staged; put in place at the switch-over)' : ''));
            if (!empty($stats['failed'])) {
                RestoreLog::add(
                    $job,
                    RestoreLog::WARNING,
                    sprintf(
                        /* translators: 1: number of files, 2: part of the site. */
                        __('%1$d files of "%2$s" could not be copied.', 'slice-backup'),
                        (int) $stats['failed'],
                        $component
                    ),
                    array(),
                    __('Check free disk space and folder permissions; the file names are in the full log.', 'slice-backup')
                );
            }
        }
        return true;
    }

    /**
     * Switches the site over to the restored version: plugin and theme folders and the
     * database tables are swapped in, and the replaced ones are kept as a safety copy.
     * Resumable: every folder move is saved before the next one, and the table swap is a
     * single atomic statement on MySQL.
     *
     * @param array<string, mixed> $state
     * @throws \Throwable When the swap fails (everything already swapped is put back first).
     */
    private static function swapStep(Job $job, array &$state, float $deadline): bool {
        if (empty($state)) {
            // From here on, requests load only this plugin and visitors see a maintenance page.
            if (RestoreGuard::install($job)) {
                RestoreLog::add($job, RestoreLog::INFO, 'Maintenance mode on: visitors see a "back in a few minutes" page; only Slice Backup loads until the restore ends.');
            }
            // Only one safety copy is kept: the previous restore's copy makes room for this one.
            if (RestoreRollback::load() !== null) {
                $old = RestoreRollback::discard();
                RestoreLog::add($job, RestoreLog::ACTION, sprintf(
                    /* translators: 1: number of tables, 2: number of folders. */
                    __('The safety copy from the previous restore was deleted (%1$d tables, %2$d folders); this restore keeps a new one.', 'slice-backup'),
                    $old['tables'],
                    $old['folders']
                ));
            }
            $folders = array();
            foreach (self::stagingRoots() as $component => $root) {
                RestoreRollback::delete($root . '/' . RestoreRollback::OLD_DIR);
                $staging = $root . '/' . RestoreRollback::STAGING_DIR;
                foreach (is_dir($staging) ? (array) scandir($staging) : array() as $entry) {
                    if (!is_string($entry) || $entry === '.' || $entry === '..') {
                        continue;
                    }
                    $folders[] = array(
                        'component' => $component,
                        'staged'    => $staging . '/' . $entry,
                        'live'      => $root . '/' . $entry,
                        'old'       => $root . '/' . RestoreRollback::OLD_DIR . '/' . $entry,
                    );
                }
            }
            $state = array('phase' => 'folders', 'folders' => $folders, 'done' => 0);
            self::checkpoint($job, 'swap', $state);
        }

        if ($state['phase'] === 'folders') {
            $count = count($state['folders']);
            for ($i = (int) $state['done']; $i < $count; $i++) {
                $folder = $state['folders'][$i];
                try {
                    if (file_exists($folder['staged'])) {
                        if (file_exists($folder['live']) && !RestoreRollback::move($folder['live'], $folder['old'])) {
                            throw new \RuntimeException(esc_html(sprintf(
                                /* translators: %s: folder path. */
                                __('Could not move %s aside. Check folder permissions.', 'slice-backup'),
                                $folder['live']
                            )));
                        }
                        if (!RestoreRollback::move($folder['staged'], $folder['live'])) {
                            throw new \RuntimeException(esc_html(sprintf(
                                /* translators: %s: folder path. */
                                __('Could not put %s in place. Check folder permissions.', 'slice-backup'),
                                $folder['live']
                            )));
                        }
                    }
                } catch (\Throwable $e) {
                    self::revertFolderSwaps($state['folders'], $i);
                    $state['done'] = 0;
                    throw $e;
                }
                $state['folders'][$i]['hadOld'] = file_exists($folder['old']);
                $state['done']                   = $i + 1;
                self::checkpoint($job, 'swap', $state);
            }
            $state['phase'] = 'tables';
            self::checkpoint($job, 'swap', $state);
            RestoreLog::add($job, RestoreLog::INFO, sprintf('%d plugin and theme folders put in place; the folders they replaced are kept as a safety copy.', $count));
        }

        if ($state['phase'] === 'tables') {
            if (empty($state['tables'])) {
                $stagePrefix = (string) $job->data['stagePrefix'];
                $target      = (string) $job->data['targetPrefix'];
                $oldPrefix   = DbUtil::internalPrefix('o');
                $live        = array_flip(DbUtil::tables($target));
                $tables      = array();
                $pairs       = array();
                foreach (!empty($job->data['dbStaged']) ? DbUtil::tables($stagePrefix) : array() as $staged) {
                    $rest  = substr($staged, strlen($stagePrefix));
                    $name  = $target . $rest;
                    $old   = isset($live[$name]) ? $oldPrefix . $rest : null;
                    if ($old !== null) {
                        $pairs[] = array($name, $old);
                    }
                    $pairs[]  = array($staged, $name);
                    $tables[] = array('live' => $name, 'old' => $old);
                }
                $state['tables'] = $tables;
                $state['pairs']  = $pairs;
                $state['oldPrefix'] = $oldPrefix;
                // Planned and saved before running, so a crash right after the swap is recognised.
                self::checkpoint($job, 'swap', $state);
            }

            $stillStaged = !empty($state['pairs']) && !empty(DbUtil::tables((string) $job->data['stagePrefix']));
            if ($stillStaged) {
                try {
                    RestoreRollback::renameTables((array) $state['pairs']);
                    // A database layer can accept RENAME TABLE without error and not actually
                    // rename anything (seen on some non-MySQL compatibility shims). Never trust
                    // the swap blindly: confirm no staging tables remain and the live ones exist.
                    $stillThere = DbUtil::tables((string) $job->data['stagePrefix']);
                    $missing    = array();
                    foreach ((array) $state['tables'] as $t) {
                        if (!DbUtil::tableExists((string) $t['live'])) {
                            $missing[] = (string) $t['live'];
                        }
                    }
                    if (!empty($stillThere) || !empty($missing)) {
                        throw new \RuntimeException(esc_html(sprintf(
                            /* translators: %s: comma-separated list of table names. */
                            __('The database tables could not be switched over on this server (RENAME TABLE had no effect). Nothing was changed. Tables involved: %s', 'slice-backup'),
                            implode(', ', array_slice(array_merge($stillThere, $missing), 0, 10))
                        )));
                    }
                } catch (\Throwable $e) {
                    self::revertFolderSwaps($state['folders'], count($state['folders']));
                    $state['done'] = 0;
                    $state['phase'] = 'folders';
                    throw $e;
                }
            }
            if (!empty($state['pairs'])) {
                // Tables are already live from here on: a crash must never "undo" the folders
                // that were already swapped in to match them.
                $job->data['dbRestored'] = true;
                $job->data['swapped']    = true;
                wp_cache_flush();
                $replaced = count(array_filter($state['tables'], static function ($t): bool {
                    return $t['old'] !== null;
                }));
                RestoreLog::add($job, RestoreLog::ACTION, sprintf(
                    /* translators: 1: number of tables, 2: number of new tables. */
                    __('The database was switched over in one step: %1$d tables replaced, %2$d added. The replaced tables are kept as a safety copy.', 'slice-backup'),
                    $replaced,
                    count($state['tables']) - $replaced
                ), array('safetyCopyPrefix' => $state['oldPrefix']));
                self::keepTargetSettings($job);
            }
            $state['phase'] = 'record';
            self::checkpoint($job, 'swap', $state);
        }

        // The safety copy: what to put back on undo. Only meaningful if something with an
        // "old" counterpart was actually replaced; a restore into an empty site has nothing
        // to roll back, so no safety copy (and no undo option) is recorded for it.
        $folders = array();
        foreach ((array) $state['folders'] as $folder) {
            $folders[] = array('live' => $folder['live'], 'old' => !empty($folder['hadOld']) ? $folder['old'] : null);
        }
        $hadTable  = !empty(array_filter((array) $state['tables'], static function ($t) {
            return $t['old'] !== null;
        }));
        $hadFolder = !empty(array_filter($folders, static function ($f) {
            return $f['old'] !== null;
        }));
        if ($hadTable || $hadFolder) {
            RestoreRollback::save(array(
                'created'    => time(),
                'job'        => $job->id,
                'bundle'     => (string) $job->options['filename'],
                'basePrefix' => (string) $job->data['targetPrefix'],
                'oldPrefix'  => (string) ($state['oldPrefix'] ?? ''),
                'tables'     => (array) ($state['tables'] ?? array()),
                'folders'    => $folders,
            ));
            RestoreLog::add($job, RestoreLog::INFO, 'Safety copy recorded: "Undo this restore" on the Restore tab (or wp slice-backup rollback) puts the previous database, plugins and themes back.');
        }
        $job->data['swapped'] = true;
        self::setProgress($job, self::stepLabel('swap'), 1.0);
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
                // Real settings from here on: the guard's filters would hide them.
                RestoreGuard::disableInThisRequest();
                if (!empty($job->data['dbRestored'])) {
                    self::keepTargetSettings($job);
                    // The restored database may name a theme that is not on this server.
                    $finalInspector = new CompatibilityInspector();
                    $finalInspector->setLogger(self::logger($job));
                    $missing = $finalInspector->safeBoot(array(), array(), (array) ($job->data['targetTheme'] ?? array()));
                    if (!empty($missing)) {
                        $job->warnings[] = sprintf(
                            /* translators: %s: theme folder name. */
                            __('The restored site uses the theme "%s", which is not installed here, so another theme was switched on. Upload that theme and activate it.', 'slice-backup'),
                            implode(', ', $missing)
                        );
                    }
                }
                self::placeMuPlugins($job);
                RestoreGuard::remove($job);

                $canRollback = RestoreRollback::info() !== null;
                RestoreLog::add($job, RestoreLog::INFO, 'Restore finished successfully.', array('canRollback' => $canRollback));
                $logFile = RestoreLog::persist($job);

                $result = array(
                    'success'             => true,
                    'message'             => __('Restore finished.', 'slice-backup'),
                    'filename'            => (string) $job->options['filename'],
                    'finishedAt'          => current_time('mysql'),
                    'summary'             => $job->data['summary'],
                    'compatibilityReport' => $job->data['report'] ?? array('items' => array(), 'summary' => array()),
                    'reloginRequired'     => !empty($job->data['dbRestored']),
                    'canRollback'         => $canRollback,
                    'logFile'             => $logFile,
                    'logUrl'              => RestoreLog::downloadUrl($logFile),
                    'actions'             => array_values((array) ($job->data['actions'] ?? array())),
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
            case 'link_import':
                if ($job->type === 'pull' || $job->type === 'link_import') {
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
        $rewritten = preg_replace('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO|UPDATE)(\s+)`' . $quoted . '/', '$1$2`' . $to, $sql, 1);
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
    private static function renamePrefixedKeys(string $from, string $to, string $tablePrefix): void {
        global $wpdb;

        $quoted   = preg_quote($from, '/');
        $usermeta = $tablePrefix . 'usermeta';
        $metaKeys = '(capabilities|user_level|user-settings|user-settings-time|dashboard_quick_press_last_post_id|persisted_preferences|media_library_mode)';

        $rows = $wpdb->get_results($wpdb->prepare('SELECT umeta_id, meta_key FROM %i WHERE meta_key LIKE %s', $usermeta, $wpdb->esc_like($from) . '%')); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time rename after a restore.
        foreach ((array) $rows as $row) {
            if (preg_match('/^' . $quoted . '((?:\d+_)?' . $metaKeys . ')$/', (string) $row->meta_key, $m)) {
                $wpdb->update($usermeta, array('meta_key' => $to . $m[1]), array('umeta_id' => $row->umeta_id)); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            }
        }

        foreach (DbUtil::optionsTables($tablePrefix) as $table) {
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
