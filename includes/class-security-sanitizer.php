<?php
/**
 * Path jail that prevents "Zip Slip" directory traversal (CWE-22) during extraction.
 *
 * @package SliceBackup
 */

declare(strict_types=1);

namespace SliceBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Archive path validation.
 */
final class SecuritySanitizer {
    /** Constant names whose value must never leave the site in a backup bundle. */
    private const WP_CONFIG_SECRET_CONSTANTS = array(
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
        'SECRET_KEY',
        'SECRET_SALT',
        'COOKIEHASH',
        'DB_PASSWORD',
        'SLICE_BACKUP_ENCRYPTION_PASSPHRASE',
    );

    /** What a redacted value is replaced with, as it appears in the copied file. */
    private const PLACEHOLDER = "'redacted-by-slice-backup'";

    /**
     * Returns wp-config.php's contents with authentication keys/salts, the cookie hash and
     * the database password replaced by a placeholder, so a backup bundle never carries them
     * off-site. Everything else (custom defines, DB_NAME/DB_HOST/DB_USER, site-specific code)
     * is kept so the file still documents the source site's configuration.
     *
     * Every way of declaring one of those constants is covered: define() and const, with the
     * value given as a quoted string, a function call such as getenv(), a concatenation, or
     * any other expression.
     */
    public static function redactWpConfig(string $contents): string {
        $tokenized = self::redactByTokens($contents);

        return $tokenized !== null ? $tokenized : self::redactByPattern($contents);
    }

    /**
     * Redacts using PHP's own tokenizer, so the value is recognised whatever shape it has
     * and a quote, bracket or semicolon inside a key never cuts the replacement short.
     * Returns null when the tokenizer extension is unavailable.
     */
    private static function redactByTokens(string $contents): ?string {
        if (!function_exists('token_get_all')) {
            return null;
        }

        $tokens = @token_get_all($contents); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A wp-config.php with a syntax error still gets redacted, not reported.
        if (!is_array($tokens) || $tokens === array()) {
            return null;
        }

        $out   = '';
        $total = count($tokens);
        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            $text  = is_array($token) ? (string) $token[1] : (string) $token;

            if (is_array($token)) {
                // const AUTH_KEY = <value>;
                if ($token[0] === T_CONST) {
                    $name  = self::next($tokens, $i);
                    $equal = $name === null ? null : self::next($tokens, $name);
                    if (
                        $name !== null && $equal !== null
                        && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING
                        && in_array((string) $tokens[$name][1], self::WP_CONFIG_SECRET_CONSTANTS, true)
                        && $tokens[$equal] === '='
                    ) {
                        $end = self::endOfStatement($tokens, $equal + 1);
                        if ($end !== null) {
                            $out .= $text . ' ' . $tokens[$name][1] . ' = ' . self::PLACEHOLDER;
                            $i    = $end - 1;
                            continue;
                        }
                    }
                }

                // define( 'AUTH_KEY', <value> );
                // Compared by text, not by token id: PHP 8 reports "\define" as one qualified
                // name token, which does not exist as a constant on PHP 7.4.
                if (ltrim(strtolower((string) $token[1]), '\\') === 'define' && !self::isMemberOrDeclaration($tokens, $i)) {
                    $open = self::next($tokens, $i);
                    $name = $open === null ? null : self::next($tokens, $open);
                    $comma = $name === null ? null : self::next($tokens, $name);
                    if (
                        $open !== null && $name !== null && $comma !== null
                        && $tokens[$open] === '('
                        && is_array($tokens[$name]) && $tokens[$name][0] === T_CONSTANT_ENCAPSED_STRING
                        && in_array(trim((string) $tokens[$name][1], '\'"'), self::WP_CONFIG_SECRET_CONSTANTS, true)
                        && $tokens[$comma] === ','
                    ) {
                        $end = self::endOfArguments($tokens, $comma + 1);
                        if ($end !== null) {
                            $out .= $text . '(' . $tokens[$name][1] . ', ' . self::PLACEHOLDER;
                            $i    = $end - 1;
                            continue;
                        }
                    }
                }
            }

            $out .= $text;
        }

        return $out;
    }

    /**
     * Index of the next token that is not whitespace or a comment, or null at the end.
     *
     * @param array<int, array{0: int, 1: string}|string> $tokens
     */
    private static function next(array $tokens, int $from): ?int {
        $total = count($tokens);
        for ($i = $from + 1; $i < $total; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /**
     * Whether this "define" is a method call or a function/method being declared rather than
     * a call to PHP's define().
     *
     * @param array<int, array{0: int, 1: string}|string> $tokens
     */
    private static function isMemberOrDeclaration(array $tokens, int $at): bool {
        for ($i = $at - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            return is_array($tokens[$i]) && in_array(
                $tokens[$i][0],
                array(T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW),
                true
            );
        }
        return false;
    }

    /**
     * Index of the ";" that ends a value expression starting at $from, or null.
     *
     * @param array<int, array{0: int, 1: string}|string> $tokens
     */
    private static function endOfStatement(array $tokens, int $from): ?int {
        $depth = 0;
        $total = count($tokens);
        for ($i = $from; $i < $total; $i++) {
            $token = $tokens[$i];
            // Matched on the token's text: "{" and "${" inside an interpolated string are
            // reported as their own token types, while the closing "}" is a plain character.
            $text = is_array($token) ? (string) $token[1] : (string) $token;
            if ($text === '(' || $text === '[' || $text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
            } elseif (!is_array($token) && $text === ';' && $depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Index of the ")" that closes an argument list already one level deep, or null.
     *
     * @param array<int, array{0: int, 1: string}|string> $tokens
     */
    private static function endOfArguments(array $tokens, int $from): ?int {
        $depth = 1;
        $total = count($tokens);
        for ($i = $from; $i < $total; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                continue;
            }
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Fallback for the rare build without the tokenizer extension. A key or password may
     * itself contain quotes, brackets and semicolons, so no pattern can reliably find where
     * its value ends: any line that names one of these constants is replaced whole. The copy
     * is documentation of the source site's configuration and is never executed or applied,
     * so losing a line of context is the right trade against leaking a secret.
     */
    private static function redactByPattern(string $contents): string {
        $names = implode('|', array_map('preg_quote', self::WP_CONFIG_SECRET_CONSTANTS));

        return (string) preg_replace_callback(
            '/^[^\r\n]*\b(' . $names . ')\b[^\r\n]*(\r?)$/m',
            static function (array $matches): string {
                return "define('" . $matches[1] . "', " . self::PLACEHOLDER . ');' . $matches[2];
            },
            $contents
        );
    }

    /**
     * Resolves an archive entry name inside $baseDirectory, or throws if it would escape it.
     *
     * @throws \RuntimeException When the entry is unsafe.
     */
    public static function assertPathInJail(string $relativePath, string $baseDirectory): string {
        if (strpos($relativePath, "\0") !== false) {
            throw new \RuntimeException(esc_html(sprintf('Null byte in archive entry: %s', $relativePath)));
        }

        $clean = str_replace('\\', '/', $relativePath);

        // Absolute paths and Windows drive letters / stream wrappers are never valid entries.
        if (preg_match('#^(/|[A-Za-z]:|[A-Za-z][A-Za-z0-9+.-]*://)#', $clean)) {
            throw new \RuntimeException(esc_html(sprintf('Absolute path in archive entry: %s', $relativePath)));
        }

        $parts = array();
        foreach (explode('/', $clean) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new \RuntimeException(esc_html(sprintf('Directory traversal in archive entry: %s', $relativePath)));
            }
            $parts[] = $part;
        }

        $base   = rtrim(wp_normalize_path((string) (realpath($baseDirectory) ?: $baseDirectory)), '/');
        $target = $base . '/' . implode('/', $parts);

        $isWindows   = DIRECTORY_SEPARATOR === '\\';
        $haystack    = $isWindows ? strtolower($target) : $target;
        $needle      = $isWindows ? strtolower($base . '/') : $base . '/';
        if (strpos($haystack . '/', $needle) !== 0) {
            throw new \RuntimeException(esc_html(sprintf('Archive entry escapes the extraction folder: %s', $relativePath)));
        }

        return $target;
    }
}
