<?php

namespace CatzConnect;

/**
 * SMS helpers that mirror the server's checks for channel "SMS", so a
 * request the server would refuse fails before it is encrypted and sent.
 */
class Sms
{
    /** Longest body the server accepts, in segments (~1,530 GSM-7 / ~670 UCS-2 characters). */
    public const MAX_SEGMENTS = 10;

    // GSM 03.38 default alphabet (ESC excluded) and extension table (ESC + char).
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM_EXTENSION = "^{}\\[~]|€\f";

    /**
     * Normalises a phone number to E.164 the way the server does: spaces,
     * dashes, dots and brackets are removed; the result must be + and 8–15
     * digits not starting with 0. Returns null when it is not E.164.
     */
    public static function normaliseE164(string $raw): ?string
    {
        $cleaned = str_replace([' ', '-', '.', '(', ')'], '', trim($raw));
        return preg_match('/^\+[1-9][0-9]{7,14}$/D', $cleaned) ? $cleaned : null;
    }

    /** A sender override: an E.164 number, or 2–11 letters/digits/spaces with a letter. */
    public static function isValidSender(string $s): bool
    {
        if (strpos($s, '+') === 0) {
            return self::normaliseE164($s) === $s;
        }
        return (bool) preg_match('/^[A-Za-z0-9 ]{2,11}$/D', $s)
            && (bool) preg_match('/[A-Za-z]/', $s)
            && $s[0] !== ' '
            && substr($s, -1) !== ' ';
    }

    /** An India DLT template id: 6–30 digits. */
    public static function isValidDltId(string $s): bool
    {
        return (bool) preg_match('/^[0-9]{6,30}$/D', $s);
    }

    /**
     * How the server will encode $text: GSM-7 when every character is in the
     * GSM alphabet (extension characters cost two septets), otherwise UCS-2.
     * 160/153 septets or 70/67 code units per part.
     *
     * @return array{encoding: string, units: int, segments: int, chars: int}
     */
    public static function analyse(string $text): array
    {
        $chars = $text === '' ? [] : preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new \Exception('The message is not valid UTF-8');
        }

        $costs = [];
        $gsm = true;
        foreach ($chars as $c) {
            if (strpos(self::GSM_BASIC, $c) !== false) {
                $costs[] = 1;
            } elseif (strpos(self::GSM_EXTENSION, $c) !== false) {
                $costs[] = 2;
            } else {
                $gsm = false;
                break;
            }
        }

        if (!$gsm) {
            // UTF-16 code units: 2 for characters outside the Basic Multilingual Plane.
            $costs = array_map(fn ($c) => strlen($c) === 4 ? 2 : 1, $chars);
        }
        $units = array_sum($costs);
        [$single, $multi] = $gsm ? [160, 153] : [70, 67];

        $segments = 1;
        if ($units > $single) {
            $used = 0;
            foreach ($costs as $c) {
                if ($used + $c > $multi) {
                    $segments++;
                    $used = 0;
                }
                $used += $c;
            }
        }

        return [
            'encoding' => $gsm ? 'gsm7' : 'ucs2',
            'units' => $units,
            'segments' => $segments,
            'chars' => count($chars),
        ];
    }

    /** Throws the server's error for a body it would refuse. */
    public static function validateBody(string $body): array
    {
        if (trim($body) === '') {
            throw new \Exception('The message is empty');
        }
        // Unicode Cc other than tab, newline and carriage return.
        if (preg_match('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{9F}]/u', $body)) {
            throw new \Exception('The message contains control characters');
        }
        $a = self::analyse($body);
        if ($a['segments'] > self::MAX_SEGMENTS) {
            throw new \Exception(sprintf(
                'The message is %d segments long; the limit is %d (%d characters in %s)',
                $a['segments'],
                self::MAX_SEGMENTS,
                $a['encoding'] === 'gsm7' ? 1530 : 670,
                strtoupper($a['encoding'])
            ));
        }
        return $a;
    }

    /**
     * The server's checks for an SMS send: Otp with verification and otp;
     * Custom with transactional/promotional and body; any other name is an
     * SMS template from the panel, filled from data. `identity` is ignored.
     */
    public static function verify(array $input): void
    {
        $type = strtolower(trim((string) ($input['type'] ?? '')));
        $template = trim((string) ($input['template'] ?? ''));
        $payload = $input['payload'] ?? [];

        $to = $payload['to'] ?? null;
        if (!is_string($to) || trim($to) === '') {
            throw new \Exception("Missing 'to' — the recipient's number in E.164, e.g. +919876543210");
        }
        $e164 = self::normaliseE164($to);
        if ($e164 === null) {
            throw new \Exception("'" . trim($to) . "' is not an E.164 phone number — use + and the country code, e.g. +919876543210");
        }

        if (!in_array($type, ['verification', 'transactional', 'promotional'], true)) {
            throw new \Exception('SMS supports type Verification (template Otp), Transactional or Promotional — not ' . ($input['type'] ?? ''));
        }

        $gateway = self::optional($payload, 'gateway');
        $sender = self::optional($payload, 'sender');
        if ($sender !== null) {
            if ($gateway === null) {
                throw new \Exception("'sender' can only be used together with 'gateway'");
            }
            if (!self::isValidSender($sender)) {
                throw new \Exception("'sender' must be an E.164 number or a 2–11 character sender ID");
            }
        }

        $dlt = self::optional($payload, 'dlt_template_id');
        if ($dlt !== null && !self::isValidDltId($dlt)) {
            throw new \Exception("'dlt_template_id' must be the numeric DLT template ID");
        }

        if (isset($payload['data'])) {
            if (!is_array($payload['data'])) {
                throw new \Exception("'data' must be an array of strings");
            }
            foreach ($payload['data'] as $k => $v) {
                if (!is_string($v)) {
                    throw new \Exception("'data.$k' must be a string");
                }
            }
        }

        if ($template === '') {
            throw new \Exception("Missing 'template' — Otp, Custom, or the name of an SMS template");
        }

        if (strtolower($template) === 'otp') {
            if ($type !== 'verification') {
                throw new \Exception('template Otp goes with type Verification');
            }
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
            if ($type === 'verification') {
                throw new \Exception('type Verification goes with template Otp; use Transactional for Custom');
            }
            $body = $payload['body'] ?? null;
            if (!is_string($body) || trim($body) === '') {
                throw new \Exception("Missing 'body'");
            }
            self::validateBody(trim($body));
            // India needs a DLT template id; a Custom body can only get it from the payload.
            if (strpos($e164, '+91') === 0 && $dlt === null) {
                throw new \Exception("India (+91) requires a DLT template ID: pass 'dlt_template_id' for Custom messages");
            }
        }
        // A panel SMS template: the server renders it from data and checks its length.
    }

    /**
     * The JSON body (before encryption) for an SMS send: only the fields the
     * server reads, with canonical casing.
     */
    public static function buildPayload(array $input, string $type, string $template): array
    {
        $payload = $input['payload'] ?? [];
        $out = [
            'message_type' => $type,
            'channel' => 'SMS',
            'template' => $template,
            'identity' => (string) ($input['identity'] ?? ''), // required by the server, ignored for SMS
            'to' => $payload['to'],
        ];
        foreach (['otp', 'body', 'gateway', 'sender', 'dlt_template_id'] as $k) {
            if (isset($payload[$k]) && is_string($payload[$k]) && trim($payload[$k]) !== '') {
                $out[$k] = $payload[$k];
            }
        }
        if (!empty($payload['data'])) {
            $out['data'] = $payload['data'];
        }
        return $out;
    }

    private static function optional(array $payload, string $field): ?string
    {
        if (!isset($payload[$field])) {
            return null;
        }
        if (!is_string($payload[$field])) {
            throw new \Exception("'$field' must be a string");
        }
        $t = trim($payload[$field]);
        return $t === '' ? null : $t;
    }
}
