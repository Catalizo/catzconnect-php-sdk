<?php

namespace CatzConnect\Core;

class VerifyPayload
{
    public static function verify(array $input): void
    {
        $type = strtolower($input['type'] ?? '');
        $channel = strtolower($input['channel'] ?? '');
        $template = strtolower($input['template'] ?? '');
        $payload = $input['payload'] ?? [];

        \CatzConnect\WhatsApp::verifyTracking($payload['tracking'] ?? null, $channel);
        \CatzConnect\PushApi::verifyTopicAndPush($payload, $channel);
        if (isset($payload['whatsapp']) && $channel !== 'whatsapp') {
            throw new \InvalidArgumentException("'whatsapp' applies to the WhatsApp channel only");
        }

        // WhatsApp media message: template Custom with whatsapp.media (any type).
        if ($channel === 'whatsapp' && $template === 'custom' && isset($payload['whatsapp']['media'])) {
            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity'");
            }
            if (!isset($payload['to'])) {
                throw new \Exception("Missing 'to'");
            }
            Validators::validatePhone($payload['to']);
            \CatzConnect\WhatsApp::verifyMedia($payload['whatsapp']['media']);
            return;
        }

        // WhatsApp approved template, sent by its Meta name.
        if ($channel === 'whatsapp' && $template !== 'otp' && $template !== 'custom') {
            if (!in_array($type, ['transactional', 'verification', 'promotional', 'notification'], true)) {
                throw new \Exception("Unsupported combination");
            }
            if (!preg_match(\CatzConnect\WhatsApp::TEMPLATE_NAME, (string) ($input['template'] ?? ''))) {
                throw new \InvalidArgumentException('A WhatsApp template name is lower-case letters, digits and underscores (the name approved at Meta)');
            }
            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity'");
            }
            if (!isset($payload['to'])) {
                throw new \Exception("Missing 'to'");
            }
            Validators::validatePhone($payload['to']);
            if (isset($payload['otp']) && !preg_match('/^[A-Za-z0-9]{1,15}$/', (string) $payload['otp'])) {
                throw new \InvalidArgumentException("'otp' must be up to 15 letters or digits");
            }
            if (isset($payload['whatsapp']['media'])) {
                throw new \InvalidArgumentException("'whatsapp.media' goes with template Custom; use 'whatsapp.header' for a template's media");
            }
            \CatzConnect\WhatsApp::verifyOptions($payload['whatsapp'] ?? null);
            return;
        }

        if ($channel === 'email' && $type === 'verification' && $template === 'otp') {

            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity'");
            }

            if (!isset($payload['to'])) {
                throw new \Exception("Missing 'to'");
            }

            if (!isset($payload['otp'])) {
                throw new \Exception("Missing 'otp'");
            }

            Validators::validateEmail($payload['to']);

            if (!is_string($payload['otp'])) {
                throw new \Exception("'otp' must be a string");
            }

            if (!ctype_digit($payload['otp'])) {
                throw new \Exception("'otp' must contain only digits");
            }

            if (strlen($payload['otp']) !== 6) {
                throw new \Exception("'otp' must be exactly 6 digits");
            }

            return;
        }

        if ($channel === 'email' && $type === 'transactional' && $template === 'custom') {

            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity'");
            }

            if (!isset($payload['to'])) {
                throw new \Exception("Missing 'to'");
            }

            if (!isset($payload['subject'])) {
                throw new \Exception("Missing 'subject'");
            }

            if (!isset($payload['body'])) {
                throw new \Exception("Missing 'body'");
            }

            Validators::validateEmail($payload['to']);

            return;
        }

        if ($channel === 'whatsapp' && $type === 'verification' && $template === 'otp') {

            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity'");
            }

            if (!isset($payload['to'])) {
                throw new \Exception("Missing 'to'");
            }

            Validators::validatePhone($payload['to']);

            if (!isset($payload['otp']) || !is_string($payload['otp'])) {
                throw new \Exception("'otp' must be a string");
            }

            // Meta's authentication templates take up to 15 letters or digits.
            if (!preg_match('/^[A-Za-z0-9]{1,15}$/', $payload['otp'])) {
                throw new \Exception("'otp' must be up to 15 letters or digits");
            }

            return;
        }

        if ($channel === 'whatsapp' && $type === 'transactional' && $template === 'custom') {

            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity'");
            }

            if (!isset($payload['to'])) {
                throw new \Exception("Missing 'to'");
            }

            Validators::validatePhone($payload['to']);

            if (!isset($payload['body']) || $payload['body'] === '') {
                throw new \Exception("Missing 'body'");
            }

            // Subject is optional for WhatsApp; the server sends it as a bold line.
            $length = mb_strlen($payload['body']) + (isset($payload['subject']) ? mb_strlen($payload['subject']) + 6 : 0);
            if ($length > 4096) {
                throw new \Exception("WhatsApp messages are limited to 4096 characters");
            }

            return;
        }

        if ($channel === 'push' && $type === 'notification' && $template === 'notification') {

            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity' — the Firebase project ID");
            }

            $hasTo = isset($payload['to']) && $payload['to'] !== '';
            $hasUser = isset($payload['external_user_id']) && $payload['external_user_id'] !== '';

            if ($hasTo && $hasUser) {
                throw new \Exception("Give either 'to' (one device token) or 'external_user_id' (a user's registered devices), not both");
            }

            // A broadcast / topic push has no single recipient (checked above).
            $many = !empty($payload['push']['broadcast']) || (isset($payload['push']['topic']) && $payload['push']['topic'] !== '');
            if (!$hasTo && !$hasUser && !$many) {
                throw new \Exception("Missing 'to' — the device's FCM registration token — or 'external_user_id' for a user's registered devices");
            }

            if ($hasTo) {
                if (!is_string($payload['to'])) {
                    throw new \Exception("'to' must be a string");
                }
                if (strpos($payload['to'], '@') !== false) {
                    throw new \Exception("'to' must be an FCM registration token, not an email address");
                }
            }

            if ($hasUser) {
                if (!is_string($payload['external_user_id']) || mb_strlen($payload['external_user_id']) > 128) {
                    throw new \Exception("'external_user_id' must be a string of up to 128 characters");
                }
                if (isset($payload['device_key'])) {
                    throw new \Exception("'device_key' cannot be used with 'external_user_id' — each registered device's own key is used");
                }
            }

            if (!isset($payload['body']) || $payload['body'] === '') {
                throw new \Exception("Missing 'body'");
            }

            foreach (['image', 'link'] as $field) {
                if (isset($payload[$field]) && strpos((string) $payload[$field], 'https://') !== 0) {
                    throw new \Exception("'$field' must be an https:// URL");
                }
            }

            if (isset($payload['data'])) {
                foreach ((array) $payload['data'] as $k => $v) {
                    if (!is_string($v)) {
                        throw new \Exception("'data.$k' must be a string — FCM only carries string values");
                    }
                }
            }

            return;
        }

        if ($channel === 'sms') {
            \CatzConnect\Sms::verify($input);
            return;
        }

        if ($channel === 'voice') {
            \CatzConnect\Voice::verify($input);
            return;
        }

        // An email template created in the panel, sent by name. The server
        // fills its {{variables}} from payload.data.
        if ($channel === 'email' && $template !== '' && !in_array($template, ['otp', 'custom', 'notification'], true)) {

            if (!isset($input['identity'])) {
                throw new \Exception("Missing 'identity'");
            }

            if (!isset($payload['to'])) {
                throw new \Exception("Missing 'to'");
            }

            Validators::validateEmail($payload['to']);

            if (isset($payload['data'])) {
                foreach ((array) $payload['data'] as $k => $v) {
                    if (!is_string($v)) {
                        throw new \Exception("'data.$k' must be a string");
                    }
                }
            }

            return;
        }

        throw new \Exception("Unsupported: $type.$channel.$template");
    }
}