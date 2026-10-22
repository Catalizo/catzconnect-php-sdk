<?php

namespace CatzConnect;

use CatzConnect\Core\HttpClient;

/**
 * Hosted one-time codes: the server makes the code, sends it and checks it.
 *
 *     $s = $client->otp()->start(['channel' => 'SMS', 'to' => '+919876543210']);
 *     $client->otp()->verify(['verification_id' => $s['verification_id'], 'code' => $typed]);
 *
 * Refusals throw ApiException with errorCode otp_invalid
 * (->details['attempts_remaining']), otp_expired, otp_attempts_exceeded,
 * otp_resend_cooldown (->details['retry_after']), otp_send_limit,
 * otp_not_found, otp_already_verified.
 */
class Otp
{
    private const CHANNELS = ['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'voice' => 'Voice'];
    private const NUMBERS = [
        'code_length' => [4, 10], 'ttl_seconds' => [60, 3600], 'max_attempts' => [1, 10], 'resend_cooldown_seconds' => [10, 3600],
    ];

    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient();
    }

    public static function buildStartPayload(array $in): array
    {
        $ch = self::CHANNELS[strtolower((string) ($in['channel'] ?? ''))] ?? null;
        if ($ch === null) {
            throw new \InvalidArgumentException("'channel' must be Email, SMS, WhatsApp or Voice");
        }
        $to = trim((string) ($in['to'] ?? ''));
        if ($to === '') {
            throw new \InvalidArgumentException("Missing 'to'");
        }
        $out = ['channel' => $ch, 'to' => $to];
        foreach (['identity', 'template', 'language', 'gateway', 'sender', 'dlt_template_id', 'provider'] as $k) {
            if (isset($in[$k]) && trim((string) $in[$k]) !== '') {
                $out[$k] = trim((string) $in[$k]);
            }
        }
        foreach (self::NUMBERS as $k => [$min, $max]) {
            if (isset($in[$k])) {
                if (!is_int($in[$k]) || $in[$k] < $min || $in[$k] > $max) {
                    throw new \InvalidArgumentException("'$k' must be from $min to $max");
                }
                $out[$k] = $in[$k];
            }
        }
        if (isset($in['whatsapp'])) {
            $out['whatsapp'] = $in['whatsapp'];
        }
        if (isset($in['data'])) {
            $out['data'] = array_map('strval', (array) $in['data']);
        }
        $ref = CatzConnect::reference($in['reference'] ?? null);
        if ($ref !== null) {
            $out['reference'] = $ref;
        }
        return $out;
    }

    public static function buildVerifyPayload(array $in): array
    {
        $code = preg_replace('/[\s-]/', '', (string) ($in['code'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9]{1,15}$/', $code)) {
            throw new \InvalidArgumentException("'code' must be the 1–15 letters or digits that were sent");
        }
        if (isset($in['verification_id'])) {
            if (!Messages::isUuid($in['verification_id'])) {
                throw new \InvalidArgumentException("'verification_id' must be a UUID");
            }
            return ['verification_id' => trim($in['verification_id']), 'code' => $code];
        }
        $ch = self::CHANNELS[strtolower((string) ($in['channel'] ?? ''))] ?? null;
        $to = trim((string) ($in['to'] ?? ''));
        if ($ch === null || $to === '') {
            throw new \InvalidArgumentException("Give 'verification_id', or 'channel' and 'to'");
        }
        return ['channel' => $ch, 'to' => $to, 'code' => $code];
    }

    /** Sends a code; 'code' is in the answer for test keys only. */
    public function start(array $in): array
    {
        return Messages::call($this->http, '/sdk/otp/start', self::buildStartPayload($in));
    }

    /** Returns ['verified' => true, …] or throws ApiException. */
    public function verify(array $in): array
    {
        return Messages::call($this->http, '/sdk/otp/verify', self::buildVerifyPayload($in));
    }
}
