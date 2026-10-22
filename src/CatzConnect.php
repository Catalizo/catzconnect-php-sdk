<?php

namespace CatzConnect;

use CatzConnect\Core\HttpClient;
use CatzConnect\Core\Crypto;
use CatzConnect\Core\VerifyPayload;

class CatzConnect
{
    public const VERSION = '2.0.0';

    private HttpClient $http;
    private ?Calls $calls = null;
    private ?Messages $messages = null;
    private ?Otp $otp = null;
    private ?Manage $manage = null;
    private ?PushApi $push = null;
    private ?string $defaultLanguage = null;

    /**
     * $http is for tests (a HttpClient whose transport() is overridden).
     * $defaultLanguage is the 'language' used when a send() does not set one
     * (en, de, ja, ar, hi, or a BCP-47 tag such as 'de-DE').
     */
    public function __construct(?HttpClient $http = null, ?string $defaultLanguage = null)
    {
        $this->http = $http ?? new HttpClient();
        $this->setDefaultLanguage($defaultLanguage);
    }

    /** Sets (or, with null, clears) the default 'language'. Throws for an unsupported one. */
    public function setDefaultLanguage(?string $language): self
    {
        $this->defaultLanguage = $language === null ? null : Language::normalise($language);
        return $this;
    }

    /**
     * Allow a plain http:// CATZCONNECT_BASE_URL to a host other than
     * localhost / 127.0.0.1 / ::1 / 10.0.2.2 (same as
     * CATZCONNECT_ALLOW_INSECURE_HTTP=1). Default false: such a URL throws
     * an \InvalidArgumentException before anything is sent.
     */
    public function setAllowInsecureHttp(bool $allow): self
    {
        $this->http->setAllowInsecureHttp($allow);
        return $this;
    }

    public function getDefaultLanguage(): ?string
    {
        return $this->defaultLanguage;
    }

    /** Voice & Video calls: create(), token(), get(), end(). */
    public function calls(): Calls
    {
        return $this->calls ??= new Calls($this->http);
    }

    /**
     * The server matches these names exactly ("Email", not "email").
     *
     * Validation here has always been case-insensitive, but send() forwarded
     * whatever casing it was given — so a lowercase call, as the README showed,
     * passed locally and was then rejected by the server as an unknown channel.
     * Normalising once, here, makes both agree.
     */
    private const CANONICAL = [
        'channel'  => ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'push' => 'Push', 'sms' => 'SMS', 'voice' => 'Voice'],
        'type'     => ['verification' => 'Verification', 'transactional' => 'Transactional', 'notification' => 'Notification', 'promotional' => 'Promotional'],
        'template' => ['otp' => 'Otp', 'custom' => 'Custom', 'notification' => 'Notification'],
    ];

    private static function canonical(string $field, $value)
    {
        $key = strtolower((string) $value);
        return self::CANONICAL[$field][$key] ?? $value;
    }

    /**
     * The JSON body (before encryption) that send() posts for $input.
     * Validates first. A top-level 'language' is normalised ('de-DE' → 'de')
     * and sent next to channel/template; without one the body is unchanged.
     */
    public static function buildPayload(array $input): array
    {
        $language = null;
        if (isset($input['language'])) {
            $language = Language::normalise($input['language']);
        }
        $out = self::buildChannelPayload($input);
        // Push options: a DateTimeInterface send_at goes as RFC 3339.
        if (isset($out['push']['send_at']) && $out['push']['send_at'] instanceof \DateTimeInterface) {
            $out['push']['send_at'] = \DateTimeImmutable::createFromInterface($out['push']['send_at'])
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
        }
        if ($language !== null) {
            $out['language'] = $language;
        }
        // Left out when unset, so existing sends post the same body as before.
        if (isset($input['idempotencyKey']) || isset($input['idempotency_key'])) {
            $out['idempotency_key'] = self::idempotencyKey($input['idempotencyKey'] ?? $input['idempotency_key']);
        }
        if (isset($input['reference'])) {
            $ref = self::reference($input['reference']);
            if ($ref !== null) {
                $out['reference'] = $ref;
            }
        }
        return $out;
    }

    /** An Idempotency-Key: 1–255 visible ASCII characters, no spaces. */
    public static function idempotencyKey($k): string
    {
        if (!is_string($k) || !preg_match('/^[\x21-\x7e]{1,255}$/', trim($k))) {
            throw new \InvalidArgumentException("'idempotencyKey' must be 1–255 visible ASCII characters without spaces");
        }
        return trim($k);
    }

    /** A reference: up to 128 characters, no control characters (blank = none). */
    public static function reference($r): ?string
    {
        if ($r === null) {
            return null;
        }
        if (!is_string($r)) {
            throw new \InvalidArgumentException("'reference' must be a string");
        }
        $t = trim($r);
        if ($t === '') {
            return null;
        }
        if (mb_strlen($t) > 128 || preg_match('/[\x00-\x1F\x7F]/', $t)) {
            throw new \InvalidArgumentException("'reference' must be up to 128 characters without line breaks or control characters");
        }
        return $t;
    }

    private static function buildChannelPayload(array $input): array
    {
        VerifyPayload::verify($input);

        if (self::canonical('channel', $input['channel'] ?? '') === 'SMS') {
            return Sms::buildPayload(
                $input,
                self::canonical('type', trim((string) $input['type'])),
                self::canonical('template', trim((string) $input['template']))
            );
        }

        if (self::canonical('channel', trim((string) ($input['channel'] ?? ''))) === 'Voice') {
            return Voice::buildPayload(
                $input,
                self::canonical('type', trim((string) $input['type'])),
                self::canonical('template', trim((string) $input['template']))
            );
        }

        return array_merge([
            'message_type' => self::canonical('type', $input['type']),
            'channel' => self::canonical('channel', $input['channel']),
            'template' => self::canonical('template', $input['template']),
            'identity' => $input['identity'],
        ], $input['payload']);
    }

    public function send(array $input): array
    {
        if (!isset($input['language']) && $this->defaultLanguage !== null) {
            $input['language'] = $this->defaultLanguage;
        }
        $finalPayload = self::buildPayload($input);

        $encrypted = Crypto::encrypt($finalPayload);

        $headers = isset($finalPayload['idempotency_key']) ? ['Idempotency-Key' => $finalPayload['idempotency_key']] : [];
        return $this->http->post('/sdk/send', $encrypted, $headers);
    }

    /** Status and event timeline of sent messages: get(), list(). */
    public function messages(): Messages
    {
        return $this->messages ??= new Messages($this->http);
    }

    /** Hosted one-time codes: start(), verify(). */
    public function otp(): Otp
    {
        return $this->otp ??= new Otp($this->http);
    }

    /** Management API (a key with the management scope). */
    public function manage(): Manage
    {
        return $this->manage ??= new Manage($this->http);
    }

    /** Push broadcasts / scheduled pushes and topic subscriptions (server key). */
    public function push(): PushApi
    {
        return $this->push ??= new PushApi($this->http);
    }

    /** Push device registry with the publishable key (no API key). */
    public function pushDevices(?string $baseUrl = null): PushDevices
    {
        return new PushDevices($this->http, $baseUrl);
    }

    /**
     * Sends an SMS ('channel' may be omitted) and returns the queued
     * message's id, segments and encoding.
     */
    public function sendSms(array $input): SmsResult
    {
        $input['channel'] = $input['channel'] ?? 'SMS';
        if (strtolower((string) $input['channel']) !== 'sms') {
            throw new \Exception('sendSms() is for the SMS channel, not ' . $input['channel']);
        }
        return SmsResult::fromResponse($this->send($input));
    }

    /**
     * Places a phone call that speaks the message ('channel' may be omitted)
     * and returns the decoded response; data.message_id is the queued call.
     */
    public function sendVoice(array $input): array
    {
        $input['channel'] = $input['channel'] ?? 'Voice';
        if (strtolower(trim((string) $input['channel'])) !== 'voice') {
            throw new \Exception('sendVoice() is for the Voice channel, not ' . $input['channel']);
        }
        $res = $this->send($input);
        if (!isset($res['data']['message_id'])) {
            throw new \Exception('Response has no data.message_id');
        }
        return $res;
    }
}