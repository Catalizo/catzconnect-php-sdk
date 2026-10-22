<?php

namespace CatzConnect;

use CatzConnect\Core\HttpClient;

/**
 * Push broadcasts / scheduled pushes and topic subscriptions from your
 * server (server key with the Push channel; app and test keys are refused).
 * A broadcast is created by send() with payload['push']:
 *
 *     $res = $client->send(['channel' => 'Push', 'type' => 'Notification', 'template' => 'Notification',
 *         'identity' => 'my-firebase-project', 'payload' => ['body' => 'Sale!', 'push' => ['topic' => 'deals',
 *         'send_at' => new DateTimeImmutable('2026-11-02 09:00 UTC')]]]);
 *     $client->push()->broadcast($res['data']['broadcast_id']);   // status, queued, skipped, over_quota…
 *     $client->push()->cancel($res['data']['broadcast_id']);
 */
class PushApi
{
    /** Email subscription topic keys (`topic` on an Email send). */
    public const EMAIL_TOPIC_KEY = '/^[a-z0-9][a-z0-9_-]{0,39}$/';
    /** Push topic names: 1–64 of A-Z a-z 0-9 _ . ~ -. */
    public const PUSH_TOPIC_NAME = '/^[A-Za-z0-9_.~-]{1,64}$/';

    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient();
    }

    /** Checks `topic` (Email) and `push` (Push) in a send payload. */
    public static function verifyTopicAndPush(array $payload, string $channel): void
    {
        $channel = strtolower($channel);
        if (isset($payload['topic'])) {
            if ($channel !== 'email') {
                throw new \InvalidArgumentException("'topic' applies to the Email channel only — for push topics use push.topic");
            }
            if (!is_string($payload['topic']) || !preg_match(self::EMAIL_TOPIC_KEY, $payload['topic'])) {
                throw new \InvalidArgumentException("'topic' must be a topic key: lower-case letters, digits, _ and -, up to 40 characters");
            }
        }
        if (!isset($payload['push'])) {
            return;
        }
        $p = $payload['push'];
        if ($channel !== 'push') {
            throw new \InvalidArgumentException("'push' applies to the Push channel only");
        }
        if (!is_array($p)) {
            throw new \InvalidArgumentException("'push' must be an array");
        }
        $broadcast = !empty($p['broadcast']);
        $topic = $p['topic'] ?? null;
        if ($broadcast && $topic !== null) {
            throw new \InvalidArgumentException('Give push.broadcast or push.topic, not both');
        }
        if ($topic !== null && (!is_string($topic) || !preg_match(self::PUSH_TOPIC_NAME, $topic))) {
            throw new \InvalidArgumentException("'push.topic' must be 1–64 characters of letters, digits, _ . ~ -");
        }
        if (isset($p['send_at']) && !($p['send_at'] instanceof \DateTimeInterface)) {
            $s = $p['send_at'];
            if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/i', trim($s)) || strtotime($s) === false) {
                throw new \InvalidArgumentException("'push.send_at' must be an RFC 3339 time with a time zone, e.g. 2026-11-02T09:00:00Z");
            }
        }
        if ($broadcast || $topic !== null) {
            if ((isset($payload['to']) && $payload['to'] !== '') || (isset($payload['external_user_id']) && $payload['external_user_id'] !== '')) {
                throw new \InvalidArgumentException("push.broadcast and push.topic send to many devices: leave 'to' and 'external_user_id' out");
            }
            if (isset($payload['device_key'])) {
                throw new \InvalidArgumentException("'device_key' is only for one device token — registered devices use their own keys");
            }
        }
    }

    /** 1–$max push topic names. */
    public static function topics(array $topics, int $max = 20): array
    {
        if (count($topics) === 0 || count($topics) > $max) {
            throw new \InvalidArgumentException("'topics' must list 1–$max topic names");
        }
        foreach ($topics as $t) {
            if (!is_string($t) || !preg_match(self::PUSH_TOPIC_NAME, $t)) {
                throw new \InvalidArgumentException('A push topic is 1–64 characters of letters, digits, _ . ~ -');
            }
        }
        return array_values($topics);
    }

    private static function id(string $id): string
    {
        if (!Messages::isUuid($id)) {
            throw new \InvalidArgumentException("'broadcast_id' must be a broadcast id (UUID)");
        }
        return trim($id);
    }

    /** Status and counters: id, audience, topic, send_at, status, queued, skipped, over_quota, error_code… */
    public function broadcast(string $broadcastId): array
    {
        return Messages::call($this->http, '/sdk/push/broadcast', ['broadcast_id' => self::id($broadcastId)]);
    }

    /** Stop one that has not finished (ErrorCodes::NOT_CANCELABLE once it has). */
    public function cancel(string $broadcastId): array
    {
        return Messages::call($this->http, '/sdk/push/cancel', ['broadcast_id' => self::id($broadcastId)]);
    }

    /** $in: identity (Firebase project id), topic, tokens (registered, ≤ 1000) and/or external_user_id. */
    public static function buildTopicChangePayload(array $in): array
    {
        $identity = trim((string) ($in['identity'] ?? ''));
        if ($identity === '') {
            throw new \InvalidArgumentException("Missing 'identity'");
        }
        $topic = self::topics([(string) ($in['topic'] ?? '')], 1)[0];
        $out = ['identity' => $identity, 'topic' => $topic];
        if (!empty($in['tokens'])) {
            if (!is_array($in['tokens']) || count($in['tokens']) > 1000) {
                throw new \InvalidArgumentException("'tokens' must list at most 1000 tokens");
            }
            $out['tokens'] = array_values($in['tokens']);
        }
        if (isset($in['external_user_id']) && trim((string) $in['external_user_id']) !== '') {
            $out['external_user_id'] = trim((string) $in['external_user_id']);
        }
        if (!isset($out['tokens']) && !isset($out['external_user_id'])) {
            throw new \InvalidArgumentException("Give 'tokens' (registered devices) or 'external_user_id'");
        }
        return $out;
    }

    /** ['topic' => …, 'devices' => matched registered devices]. */
    public function subscribe(array $in): array
    {
        return Messages::call($this->http, '/sdk/push/topic/subscribe', self::buildTopicChangePayload($in));
    }

    public function unsubscribe(array $in): array
    {
        return Messages::call($this->http, '/sdk/push/topic/unsubscribe', self::buildTopicChangePayload($in));
    }

    /** ['topics' => [['topic', 'devices', 'last_subscribed_at'], …]]. */
    public function topicsOf(string $identity): array
    {
        if (trim($identity) === '') {
            throw new \InvalidArgumentException("Missing 'identity'");
        }
        return Messages::call($this->http, '/sdk/push/topics', ['identity' => trim($identity)]);
    }
}
