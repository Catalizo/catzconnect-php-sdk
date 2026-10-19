<?php

namespace CatzConnect;

/**
 * Phone calls (text-to-speech) through send() with channel "Voice". These
 * mirror the server's checks, so a refused request fails before it is
 * encrypted and sent.
 */
class Voice
{
    /** Longest Custom text a phone call speaks. */
    public const MAX_CHARS = 1000;
    public const MAX_REPEAT = 3;

    /** A language tag such as en-US, hi-IN, es-419 or fr. */
    public static function isValidLanguage(string $s): bool
    {
        return (bool) preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/D', $s);
    }

    /** female, male, or a provider voice name such as Polly.Joanna or en-US-Wavenet-F. */
    public static function isValidVoiceName(string $s): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._:\- ]{1,64}$/D', $s) && trim($s) === $s;
    }

    /** Throws the server's error for a Custom text it would refuse. */
    public static function validateText(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            throw new \Exception('The text to speak is empty');
        }
        if (preg_match('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{9F}]/u', $t)) {
            throw new \Exception('The text to speak contains control characters');
        }
        $n = preg_match_all('/./su', $t);
        if ($n === false) {
            throw new \Exception('The text to speak is not valid UTF-8');
        }
        if ($n > self::MAX_CHARS) {
            throw new \Exception("The text to speak is $n characters; the limit is " . self::MAX_CHARS);
        }
        return $t;
    }

    /** data.repeat as the server reads it: a whole number 1-3 (string or int). */
    public static function parseRepeat($v): int
    {
        $s = is_int($v) ? (string) $v : (is_string($v) ? trim($v) : '');
        if (!preg_match('/^[0-9]+$/D', $s) || (int) $s < 1 || (int) $s > self::MAX_REPEAT) {
            throw new \Exception("'data.repeat' must be a whole number from 1 to 3");
        }
        return (int) $s;
    }

    /**
     * The server's checks for channel Voice: an E.164 'to'; template Otp
     * with 'otp' (up to 15 letters or digits, read out one by one), Custom
     * with 'body' (at most 1,000 characters), or the name of a Voice
     * template from the panel filled from 'data'. data.language, data.voice
     * and data.repeat are optional. 'identity' is ignored.
     */
    public static function verify(array $input): void
    {
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        $template = trim((string) ($input['template'] ?? ''));
        $payload = $input['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \Exception("Missing 'payload'");
        }

        $to = $payload['to'] ?? null;
        if (!is_string($to) || trim($to) === '') {
            throw new \Exception("Missing 'to' — the number to call in E.164, e.g. +14155550100");
        }
        if (Sms::normaliseE164($to) === null) {
            throw new \Exception("'" . trim($to) . "' is not an E.164 phone number — use + and the country code, e.g. +919876543210");
        }

        if (!in_array($type, ['verification', 'transactional', 'notification', 'promotional'], true)) {
            throw new \Exception('Voice supports type Verification, Transactional, Notification or Promotional — not ' . ($input['type'] ?? ''));
        }

        if (isset($payload['provider']) && (!is_string($payload['provider']) || trim($payload['provider']) === '')) {
            throw new \Exception("'provider' must be a phone provider's id or label");
        }

        if (isset($payload['data'])) {
            if (!is_array($payload['data'])) {
                throw new \Exception("'data' must be an array of strings");
            }
            foreach ($payload['data'] as $k => $v) {
                if ($k === 'repeat') {
                    self::parseRepeat($v);
                    continue;
                }
                if (!is_string($v)) {
                    throw new \Exception("'data.$k' must be a string");
                }
                if ($k === 'language' && !self::isValidLanguage($v)) {
                    throw new \Exception("'data.language' must be a language tag such as en-US");
                }
                if ($k === 'voice' && !self::isValidVoiceName($v)) {
                    throw new \Exception("'data.voice' must be female, male or a provider voice name");
                }
            }
        }

        if ($template === '') {
            throw new \Exception("Missing 'template' — Otp, Custom, or the name of a Voice template");
        }
        if (strtolower($template) === 'otp') {
            $otp = $payload['otp'] ?? null;
            if (!is_string($otp) || trim($otp) === '') {
                throw new \Exception("Missing 'otp' — a string, so leading zeros are kept");
            }
            if (!preg_match('/^[A-Za-z0-9]{1,15}$/D', trim($otp))) {
                throw new \Exception("'otp' must be up to 15 letters or digits");
            }
            return;
        }
        if (strtolower($template) === 'custom') {
            $body = $payload['body'] ?? null;
            if (!is_string($body) || trim($body) === '') {
                throw new \Exception("Missing 'body' — the text to speak");
            }
            self::validateText($body);
        }
        // A panel Voice template: the server renders it from data.
    }

    /** The JSON body (before encryption) for a Voice send: only the fields the server reads. */
    public static function buildPayload(array $input, string $type, string $template): array
    {
        $payload = $input['payload'] ?? [];
        $out = [
            'message_type' => $type,
            'channel' => 'Voice',
            'template' => $template,
            'identity' => (string) ($input['identity'] ?? ''), // required by the server, ignored for Voice
            'to' => Sms::normaliseE164((string) $payload['to']),
        ];
        foreach (['otp', 'body', 'provider'] as $k) {
            if (isset($payload[$k]) && is_string($payload[$k]) && trim($payload[$k]) !== '') {
                $out[$k] = trim($payload[$k]);
            }
        }
        if (!empty($payload['data'])) {
            $data = [];
            foreach ($payload['data'] as $k => $v) {
                // The server's data is a map of strings; repeat travels as "1".."3".
                $data[(string) $k] = $k === 'repeat' ? (string) self::parseRepeat($v) : (string) $v;
            }
            $out['data'] = $data;
        }
        return $out;
    }
}
