<?php

namespace CatzConnect\Core;

/**
 * Read a configuration value the way PHP applications actually provide it.
 *
 * phpdotenv's recommended Dotenv::createImmutable() fills $_ENV and $_SERVER
 * but not getenv(). Reading getenv() alone — as this SDK used to — meant the
 * standard setup left every key "missing" even when correctly named.
 *
 * Values are trimmed: a trailing newline in an API key makes the server's
 * exact-match lookup fail. This matches the Go and Rust SDKs.
 */
class Env
{
    public static function get(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
