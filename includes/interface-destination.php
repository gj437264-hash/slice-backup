<?php
/**
 * A remote place where bundles are stored (S3-compatible bucket, SFTP server, Dropbox).
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Remote bundle storage.
 */
interface Destination {
    /**
     * Proves the settings work by writing, listing and deleting a small file.
     *
     * @return string Success message.
     * @throws \Throwable With a readable message on failure.
     */
    public function test(): string;

    /**
     * @return array<int, array{filename: string, size: float, modified: int}>
     */
    public function listBundles(): array;

    public function delete(string $filename): void;

    /**
     * Uploads a local file a piece at a time. Returns true when finished.
     *
     * @param array<string, mixed> $state Resumable state, initially empty.
     */
    public function uploadStep(string $path, string $filename, array &$state, float $deadline): bool;

    /**
     * Releases a partial upload (best effort).
     *
     * @param array<string, mixed> $state
     */
    public function abortUpload(array $state): void;

    /**
     * Downloads a remote bundle into $target a piece at a time. Returns true when finished.
     *
     * @param array<string, mixed> $state Resumable state, initially empty; must contain 'offset' and 'size'.
     */
    public function downloadStep(string $filename, string $target, array &$state, float $deadline): bool;
}
