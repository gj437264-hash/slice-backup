<?php
/**
 * Error returned by the other site during a site-to-site transfer.
 *
 * @package WpBundleMigrator
 */

declare(strict_types=1);

namespace WpBundleMigrator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A failed request to the other site, with its HTTP status and decoded body.
 */
final class PeerError extends \RuntimeException {
    /** @var int */
    public $status;
    /** @var array<string, mixed> */
    public $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $message, int $status, array $data = array()) {
        parent::__construct($message);
        $this->status = $status;
        $this->data   = $data;
    }
}
