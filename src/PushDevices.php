<?php

namespace CatzConnect;

use CatzConnect\Core\HttpClient;

/**
 * Push device registry: POST /push/register, /push/unregister, /push/opened
 * with the project's publishable key (no API key, plain JSON).
 *
 *     $client->pushDevices()->register(['publishable_key' => 'pk_push_…', 'token' => $fcm, 'platform' => 'android',
 *         'external_user_id' => $user->id, 'user_hash' => Push::computeUserHash($user->id, $identitySecret)]);
 */
class PushDevices
{
    private HttpClient $http;
    private ?string $baseUrl;

    public function __construct(?HttpClient $http = null, ?string $baseUrl = null)
    {
        $this->http = $http ?? new HttpClient();
        $this->baseUrl = $baseUrl;
    }

    private static function key($k): string
    {
        if (!is_string($k) || strlen(trim($k)) < 10 || strlen(trim($k)) > 100) {
            throw new \InvalidArgumentException("'publishable_key' is required");
        }
        return trim($k);
    }

    private static function token($t): string
    {
        if (!is_string($t) || strlen(trim($t)) < 32 || strlen(trim($t)) > 4096) {
            throw new \InvalidArgumentException("'token' must be the device's FCM registration token");
        }
        return trim($t);
    }

    public static function buildRegisterPayload(array $in): array
    {
        if (!in_array($in['platform'] ?? null, ['ios', 'android', 'web'], true)) {
            throw new \InvalidArgumentException("'platform' must be ios, android or web");
        }
        $out = ['publishable_key' => self::key($in['publishable_key'] ?? null), 'token' => self::token($in['token'] ?? null), 'platform' => $in['platform']];
        if (isset($in['external_user_id'])) {
            $u = (string) $in['external_user_id'];
            if ($u === '' || mb_strlen($u) > 128 || preg_match('/[\x00-\x1F\x7F]/', $u)) {
                throw new \InvalidArgumentException("'external_user_id' must be 1–128 characters");
            }
            $out['external_user_id'] = $u;
        }
        foreach (['user_hash', 'app_version', 'device_key'] as $k) {
            if (isset($in[$k])) {
                $out[$k] = (string) $in[$k];
            }
        }
        if (isset($out['app_version']) && mb_strlen($out['app_version']) > 40) {
            throw new \InvalidArgumentException("'app_version' is at most 40 characters");
        }
        return $out;
    }

    private function data(array $res): array
    {
        return isset($res['data']) && is_array($res['data']) ? $res['data'] : $res;
    }

    /** ['device_id' => …, 'created' => bool]. */
    public function register(array $in): array
    {
        return $this->data($this->http->postPublic('/push/register', self::buildRegisterPayload($in), $this->baseUrl));
    }

    /** ['removed' => bool]; never fails for an unknown token. */
    public function unregister(string $publishableKey, string $token): array
    {
        return $this->data($this->http->postPublic('/push/unregister', ['publishable_key' => self::key($publishableKey), 'token' => self::token($token)], $this->baseUrl));
    }

    /** Subscribe this registered device to 1–20 push topics: ['topics' => [...]]. 404 when the token is not registered. */
    public function subscribeTopics(string $publishableKey, string $token, array $topics): array
    {
        return $this->data($this->http->postPublic('/push/topics/subscribe', ['publishable_key' => self::key($publishableKey), 'token' => self::token($token), 'topics' => PushApi::topics($topics)], $this->baseUrl));
    }

    /** Unsubscribe this registered device from push topics. */
    public function unsubscribeTopics(string $publishableKey, string $token, array $topics): array
    {
        return $this->data($this->http->postPublic('/push/topics/unsubscribe', ['publishable_key' => self::key($publishableKey), 'token' => self::token($token), 'topics' => PushApi::topics($topics)], $this->baseUrl));
    }

    /**
     * ['recorded' => bool, 'verified' => bool]; $messageId is the
     * notification's catz_id. Pass the device's FCM registration $token
     * whenever the app has it: the open is then verified (only the device
     * the notification was delivered to can report it) and the first
     * verified report fires the push.opened webhook. Without it the open is
     * still recorded, but no webhook fires.
     */
    public function opened(string $publishableKey, string $messageId, ?string $token = null): array
    {
        if (!Messages::isUuid($messageId)) {
            throw new \InvalidArgumentException("'message_id' must be the catz_id from the notification");
        }
        $body = ['publishable_key' => self::key($publishableKey), 'message_id' => trim($messageId)];
        if ($token !== null && trim($token) !== '') {
            $body['token'] = self::token($token);
        }
        return $this->data($this->http->postPublic('/push/opened', $body, $this->baseUrl));
    }
}
