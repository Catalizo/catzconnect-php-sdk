<?php

namespace CatzConnect;

use CatzConnect\Core\HttpClient;
use CatzConnect\Core\Crypto;
use CatzConnect\Core\VerifyPayload;

class CatzConnect
{
    public const VERSION = '1.3.0';

    private HttpClient $http;

    public function __construct()
    {
        $this->http = new HttpClient();
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
        'channel'  => ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'push' => 'Push', 'sms' => 'SMS'],
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
     * Validates first.
     */
    public static function buildPayload(array $input): array
    {
        VerifyPayload::verify($input);

        if (self::canonical('channel', $input['channel'] ?? '') === 'SMS') {
            return Sms::buildPayload(
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
        $finalPayload = self::buildPayload($input);

        $encrypted = Crypto::encrypt($finalPayload);

        return $this->http->post('/sdk/send', $encrypted);
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
}