<?php

namespace CatzConnect;

/**
 * Checks for WhatsApp approved-template parameters and media messages, as
 * the server applies them. send() calls these.
 *
 *     'whatsapp' => ['language' => 'en_US', 'header' => ['type' => 'text', 'text' => '#1234'],
 *                    'body' => ['Ravi', '#1234'], 'buttons' => [['index' => 0, 'type' => 'url', 'text' => 'abc']]]
 *     'whatsapp' => ['media' => ['type' => 'document', 'link' => 'https://…/i.pdf', 'filename' => 'i.pdf']]
 */
final class WhatsApp
{
    public const TEMPLATE_NAME = '/^[a-z0-9_]{1,512}$/';

    private static function mediaRef(string $kind, array $m, string $where): void
    {
        $hasLink = isset($m['link']) && $m['link'] !== '';
        $hasId = isset($m['id']) && $m['id'] !== '';
        if ($hasLink === $hasId) {
            throw new \InvalidArgumentException("$where: give exactly one of 'link' (https) or 'id' (Meta media id)");
        }
        if ($hasLink && (!is_string($m['link']) || !preg_match('#^https://\S+$#', $m['link']) || strlen($m['link']) > 2048)) {
            throw new \InvalidArgumentException("$where.link must be an https:// URL");
        }
        if ($hasId && !preg_match('/^\d{1,64}$/', (string) $m['id'])) {
            throw new \InvalidArgumentException("$where.id must be a Meta media id (digits)");
        }
        if (isset($m['filename'])) {
            if ($kind !== 'document') {
                throw new \InvalidArgumentException("$where.filename is for documents only");
            }
            if (!is_string($m['filename']) || mb_strlen($m['filename']) > 240 || preg_match('#[\\\\/]#', $m['filename'])) {
                throw new \InvalidArgumentException("$where.filename must be up to 240 characters without slashes");
            }
        }
    }

    public static function verifyMedia($m): void
    {
        if (!is_array($m)) {
            throw new \InvalidArgumentException("'whatsapp.media' must be an array");
        }
        $type = $m['type'] ?? null;
        if (!in_array($type, ['image', 'document', 'video', 'audio'], true)) {
            throw new \InvalidArgumentException("'whatsapp.media.type' must be image, document, video or audio");
        }
        self::mediaRef($type, $m, "'whatsapp.media'");
        if (isset($m['caption'])) {
            if ($type === 'audio') {
                throw new \InvalidArgumentException("'whatsapp.media.caption' is not allowed for audio");
            }
            if (!is_string($m['caption']) || mb_strlen($m['caption']) > 1024) {
                throw new \InvalidArgumentException("'whatsapp.media.caption' is at most 1024 characters");
            }
        }
    }

    private static function param($v, string $where): void
    {
        if (!is_string($v) || trim($v) === '' || mb_strlen($v) > 1024 || preg_match("/[\n\t]/", $v) || strpos($v, '     ') !== false) {
            throw new \InvalidArgumentException("$where must be non-empty text of up to 1024 characters without new lines, tabs or 5+ spaces in a row");
        }
    }

    public static function verifyOptions($o): void
    {
        if ($o === null) {
            return;
        }
        if (!is_array($o)) {
            throw new \InvalidArgumentException("'whatsapp' must be an array");
        }
        if (isset($o['language']) && !preg_match('/^[A-Za-z]{2,3}(_[A-Za-z0-9]{2,4})?$/', (string) $o['language'])) {
            throw new \InvalidArgumentException("'whatsapp.language' must be a Meta language code such as en, en_US or es_419");
        }
        if (isset($o['header'])) {
            $h = $o['header'];
            $t = $h['type'] ?? null;
            if ($t === 'text') {
                if (!is_string($h['text'] ?? null) || trim($h['text']) === '' || mb_strlen($h['text']) > 60) {
                    throw new \InvalidArgumentException("'whatsapp.header.text' must be 1–60 characters");
                }
            } elseif (in_array($t, ['image', 'video', 'document'], true)) {
                self::mediaRef($t, $h, "'whatsapp.header'");
            } elseif ($t === 'location') {
                if (!is_numeric($h['latitude'] ?? null) || !is_numeric($h['longitude'] ?? null)) {
                    throw new \InvalidArgumentException("'whatsapp.header' location needs latitude and longitude");
                }
            } else {
                throw new \InvalidArgumentException("'whatsapp.header.type' must be text, image, video, document or location");
            }
        }
        if (isset($o['body'])) {
            if (!is_array($o['body'])) {
                throw new \InvalidArgumentException("'whatsapp.body' must be a list or a map of strings");
            }
            foreach ($o['body'] as $k => $v) {
                self::param($v, "'whatsapp.body[$k]'");
            }
        }
        if (isset($o['buttons'])) {
            $seen = [];
            foreach ((array) $o['buttons'] as $b) {
                $i = $b['index'] ?? null;
                if (!is_int($i) || $i < 0 || $i > 9) {
                    throw new \InvalidArgumentException("'whatsapp.buttons[].index' must be 0–9");
                }
                if (isset($seen[$i])) {
                    throw new \InvalidArgumentException("'whatsapp.buttons' lists index $i twice");
                }
                $seen[$i] = true;
                switch ($b['type'] ?? null) {
                    case 'url':
                        if (!is_string($b['text'] ?? null) || $b['text'] === '' || preg_match('/\s/', $b['text']) || strlen($b['text']) > 2000) {
                            throw new \InvalidArgumentException("A url button needs 'text': the URL suffix without spaces");
                        }
                        break;
                    case 'quick_reply':
                        if (isset($b['payload']) && (!is_string($b['payload']) || mb_strlen($b['payload']) > 256)) {
                            throw new \InvalidArgumentException("A quick_reply 'payload' is at most 256 characters");
                        }
                        break;
                    case 'copy_code':
                        if (!is_string($b['code'] ?? null) || $b['code'] === '' || mb_strlen($b['code']) > 20) {
                            throw new \InvalidArgumentException("A copy_code button needs 'code' of up to 20 characters");
                        }
                        break;
                    case 'otp':
                        if (!is_string($b['code'] ?? null) || !preg_match('/^[A-Za-z0-9]{1,15}$/', $b['code'])) {
                            throw new \InvalidArgumentException("An otp button needs 'code': up to 15 letters or digits");
                        }
                        break;
                    default:
                        throw new \InvalidArgumentException("'whatsapp.buttons[].type' must be url, quick_reply, copy_code or otp");
                }
            }
        }
        if (isset($o['media'])) {
            self::verifyMedia($o['media']);
        }
    }

    /** 'tracking' (Email only): ['opens' => bool, 'clicks' => bool]. */
    public static function verifyTracking($t, string $channel): void
    {
        if ($t === null) {
            return;
        }
        if ($channel !== 'email') {
            throw new \InvalidArgumentException("'tracking' applies to the Email channel only");
        }
        if (!is_array($t)) {
            throw new \InvalidArgumentException("'tracking' must be an array ['opens' => bool, 'clicks' => bool]");
        }
        foreach ($t as $k => $v) {
            if ($k !== 'opens' && $k !== 'clicks') {
                throw new \InvalidArgumentException("'tracking.$k' is not a tracking option (opens, clicks)");
            }
            if (!is_bool($v)) {
                throw new \InvalidArgumentException("'tracking.$k' must be true or false");
            }
        }
    }
}
