<?php
/**
 * Dropbox API error.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A Dropbox API error with its HTTP status and machine-readable summary.
 */
final class DropboxError extends \RuntimeException {
    /** @var int */
    public $status;
    /** @var string */
    public $summary;
    /** @var array<string, mixed> */
    public $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $message, int $status, string $summary, array $data = array()) {
        parent::__construct($message);
        $this->status  = $status;
        $this->summary = $summary;
        $this->data    = $data;
    }

    /**
     * Creates the error with an HTML-escaped message.
     *
     * @param array<string, mixed> $data
     */
    public static function make(string $message, int $status, string $summary, array $data = array()): self {
        return new self(esc_html($message), $status, $summary, $data);
    }
}
