<?php
/**
 * A resumable background job (export, restore, cloud upload or cloud download).
 *
 * Job state lives in a JSON file inside the protected storage folder, not in the
 * database, so it survives a restore that replaces the database.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Job state and locking.
 */
final class Job {
    /** A running job not touched for this long is considered stalled (it can be resumed or cancelled). */
    public const STALL_SECONDS = 600;

    /** @var string */
    public $id = '';
    /** @var string export|restore|upload|download */
    public $type = '';
    /** @var string running|done|failed|cancelled */
    public $status = 'running';
    /** @var string[] */
    public $steps = array();
    /** @var int */
    public $stepIndex = 0;
    /** @var array<string, mixed> Options the job was started with. */
    public $options = array();
    /** @var array<string, mixed> Per-step resumable state. */
    public $state = array();
    /** @var array<string, mixed> Data shared between steps (paths, names, report). */
    public $data = array();
    /** @var array{label: string, percent: float, detail: string} */
    public $progress = array('label' => '', 'percent' => 0.0, 'detail' => '');
    /** @var string */
    public $error = '';
    /** @var string Random secret that authorizes ticks (also after the user table is replaced). */
    public $token = '';
    /** @var string manual|schedule|cli|rest */
    public $origin = 'manual';
    /** @var int */
    public $created = 0;
    /** @var int */
    public $updated = 0;
    /** @var string[] Non-fatal problems collected along the way. */
    public $warnings = array();
    /**
     * Crash recovery: per-step slow-down level, and the crashes seen at the current position.
     *
     * @var array{levels?: array<string, int>, cursor?: string, crashes?: int, total?: int, lastError?: string, note?: string}
     */
    public $recovery = array();

    public static function root(): string {
        $dir = Storage::dir() . '/jobs';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    private static function activePointer(): string {
        return self::root() . '/active';
    }

    /**
     * Creates and registers a new job. Only one job may be active at a time.
     *
     * @param string[]             $steps
     * @param array<string, mixed> $options
     * @throws \RuntimeException When another job is running.
     */
    public static function create(string $type, array $steps, array $options, string $origin): self {
        $active = self::active();
        if ($active !== null) {
            if (!$active->isStalled()) {
                throw new \RuntimeException(esc_html__('Another backup or restore is already running. Wait for it to finish or cancel it, then try again.', 'slice-backup'));
            }
            // Through the engine, so a stalled restore is ended safely (problem plugins off, guard removed).
            if (!MigratorEngine::endStalled($active, __('Stopped because it stalled and a new job was started.', 'slice-backup'))) {
                throw new \RuntimeException(esc_html__('Another backup or restore is still finishing. Try again in a minute.', 'slice-backup'));
            }
        }

        $job          = new self();
        $job->id      = gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(8, false));
        $job->type    = $type;
        $job->steps   = array_values($steps);
        $job->options = $options;
        $job->origin  = $origin;
        $job->token   = wp_generate_password(40, false);
        $job->created = time();
        // Saved now: a restore replaces siteurl, and background ticks must keep reaching this server.
        $job->options['_ajaxUrl'] = admin_url('admin-ajax.php');
        $job->options['_siteUrl'] = home_url();

        wp_mkdir_p($job->workDir());

        // Exclusive create: fails if another request registered a job at the same moment.
        $handle = @fopen(self::activePointer(), 'x'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            Storage::removeDirectory($job->workDir());
            throw new \RuntimeException(esc_html__('Another backup or restore is already running.', 'slice-backup'));
        }
        fwrite($handle, $job->id); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        $job->save();
        return $job;
    }

    /**
     * The currently registered job, or null.
     */
    public static function active(): ?self {
        $pointer = self::activePointer();
        if (!is_file($pointer)) {
            return null;
        }
        $id  = trim((string) file_get_contents($pointer)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $job = $id !== '' ? self::load($id) : null;
        if ($job === null || $job->status !== 'running') {
            wp_delete_file($pointer);
            return null;
        }
        return $job;
    }

    public static function load(string $id): ?self {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-z0-9]{8}$/', $id)) {
            return null;
        }
        $file = self::root() . '/' . $id . '/state.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if (!is_array($raw)) {
            return null;
        }
        $job = new self();
        foreach (get_object_vars($job) as $key => $default) {
            if (array_key_exists($key, $raw)) {
                $job->$key = $raw[$key];
            }
        }
        return $job;
    }

    /**
     * Writes the state atomically (temp file + rename). A failed write (full disk)
     * keeps the previous state file instead of replacing it with a broken one.
     */
    public function save(): void {
        $this->updated = time();
        $file          = $this->workDir() . '/state.json';
        $tmp           = $file . '.tmp';
        $json          = (string) wp_json_encode(get_object_vars($this));
        $written       = file_put_contents($tmp, $json); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ($written === strlen($json)) {
            @rename($tmp, $file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
        } else {
            wp_delete_file($tmp);
        }
    }

    /**
     * Marks a tick as running. If the PHP process dies without clearing it (fatal error,
     * out of memory, killed by the host), the next tick finds the marker and knows.
     */
    public function markInflight(string $cursor): void {
        file_put_contents($this->workDir() . '/inflight', $cursor); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }

    public function clearInflight(): void {
        $file = $this->workDir() . '/inflight';
        if (is_file($file)) {
            wp_delete_file($file);
        }
    }

    /**
     * The tick that died without clearing its marker: its cursor, and the PHP error the
     * shutdown handler added (empty when the process was killed outright). Null if none.
     *
     * @return array{cursor: string, error: string}|null
     */
    public function abandonedInflight(): ?array {
        $file = $this->workDir() . '/inflight';
        if (!is_file($file)) {
            return null;
        }
        $parts = explode("\n", (string) file_get_contents($file), 2); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return array('cursor' => trim($parts[0]), 'error' => trim($parts[1] ?? ''));
    }

    /**
     * Asks the request that is working on the job to cancel it (it holds the tick lock,
     * so it stops at a safe point and cleans up itself).
     */
    public function requestCancel(): void {
        file_put_contents($this->workDir() . '/cancel.request', (string) time()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }

    public function cancelRequested(): bool {
        return is_file($this->workDir() . '/cancel.request');
    }

    public function workDir(): string {
        return self::root() . '/' . $this->id;
    }

    public function step(): string {
        return $this->steps[$this->stepIndex] ?? '';
    }

    public function isStalled(): bool {
        return $this->status === 'running' && (time() - $this->updated) > self::STALL_SECONDS;
    }

    public function checkToken(string $token): bool {
        return $token !== '' && hash_equals($this->token, $token);
    }

    public function finish(): void {
        $this->status   = 'done';
        $this->progress = array('label' => __('Finished', 'slice-backup'), 'percent' => 100.0, 'detail' => '');
        $this->save();
        $this->release();
    }

    public function fail(string $message): void {
        $this->status = 'failed';
        $this->error  = $message;
        $this->save();
        $this->release();
    }

    public function cancel(): void {
        $this->status = 'cancelled';
        $this->error  = __('Cancelled.', 'slice-backup');
        $this->save();
        $this->release();
    }

    private function release(): void {
        $pointer = self::activePointer();
        if (is_file($pointer) && trim((string) file_get_contents($pointer)) === $this->id) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            wp_delete_file($pointer);
        }
    }

    /**
     * Prevents two requests from working on the same job at once.
     *
     * @return resource|null Lock handle, or null when another request holds it.
     */
    public function lockTick() {
        $handle = fopen($this->workDir() . '/tick.lock', 'c'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return null;
        }
        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function unlockTick($handle): void {
        flock($handle, LOCK_UN);
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    }

    /**
     * Public view of the job for the UI (never includes the token).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return array(
            'id'       => $this->id,
            'type'     => $this->type,
            'status'   => $this->status,
            'step'     => $this->step(),
            'progress' => $this->progress,
            'error'    => $this->error,
            'warnings' => $this->warnings,
            'result'   => $this->status === 'done' ? ($this->data['result'] ?? null) : null,
            'stalled'  => $this->isStalled(),
            'recovery' => (string) ($this->recovery['note'] ?? ''),
            'logFile'  => (string) ($this->data['logFile'] ?? ''),
            'actions'  => $this->status === 'running' ? array() : array_values((array) ($this->data['actions'] ?? array())),
            'origin'   => $this->origin,
            'created'  => $this->created,
            'updated'  => $this->updated,
        );
    }

    /**
     * Deletes the work folders of finished jobs older than a day.
     */
    public static function cleanupOld(): void {
        $root   = self::root();
        $active = self::active();
        foreach ((array) scandir($root) as $entry) {
            if (!is_string($entry) || !preg_match('/^[0-9]{8}-[0-9]{6}-[a-z0-9]{8}$/', $entry)) {
                continue;
            }
            if ($active !== null && $active->id === $entry) {
                continue;
            }
            $state = $root . '/' . $entry . '/state.json';
            $age   = is_file($state) ? time() - (int) filemtime($state) : PHP_INT_MAX;
            if ($age > DAY_IN_SECONDS) {
                Storage::removeDirectory($root . '/' . $entry);
            }
        }
    }
}
