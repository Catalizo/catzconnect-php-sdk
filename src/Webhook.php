<?php

namespace CatzConnect;

/**
 * Verify webhook deliveries from CatzConnect.
 *
 *     $ok = \CatzConnect\Webhook::verify(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_CATZ_SIGNATURE'] ?? '',
 *         getenv('CATZCONNECT_WEBHOOK_SECRET')
 *     );
 */
class Webhook
{
    /** Seconds a delivery's timestamp may be from now before it is refused. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Check a Catz-Signature header: "t=<unix seconds>,v1=<hex HMAC-SHA256>",
     * where the MAC is taken over "<t>.<raw body>" with the webhook's signing
     * secret (whsec_…) as the key. Pass the body exactly as received, before
     * json_decode(). A tolerance of 0 skips the timestamp check.
     */
    public static function verify(
        string $rawBody,
        ?string $header,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null
    ): bool {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $key = trim($pair[0]);
            $value = trim($pair[1]);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || count($signatures) === 0) {
            return false;
        }

        $now = $now ?? time();
        if ($tolerance > 0 && abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        foreach ($signatures as $signature) {
            if (strlen($signature) === 64 && hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }
        return false;
    }
}
