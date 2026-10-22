<?php

namespace CatzConnect;

use CatzConnect\Core\Crypto;
use CatzConnect\Core\HttpClient;

/**
 * Status and event timeline of sent messages (any channel).
 *
 *     $m = $client->messages()->get($res['data']['message_id']);   // ['status' => 'delivered', 'events' => [...]]
 *     $page = $client->messages()->list(['recipient' => '+919876543210']);
 *
 * Status is normalised: queued, deferred, sending, sent, delivered, read,
 * ringing, answered, completed, failed, undelivered, suppressed, bounced,
 * complained, unknown. Refusals throw ApiException.
 */
class Messages
{
    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient();
    }

    /** POSTs an encrypted /sdk body and returns `data`. */
    public static function call(HttpClient $http, string $path, array $body): array
    {
        $res = $http->request($path, Crypto::encrypt($body));
        return isset($res['data']) && is_array($res['data']) ? $res['data'] : $res;
    }

    public static function isUuid($v): bool
    {
        return is_string($v) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($v));
    }

    public function get(string $messageId): array
    {
        if (!self::isUuid($messageId)) {
            throw new \InvalidArgumentException("'messageId' must be a message id (UUID)");
        }
        return self::call($this->http, '/sdk/messages/get', ['message_id' => trim($messageId)]);
    }

    /** The body list() posts: recipient, reference and/or broadcast_id (a push broadcast's deliveries); channel, limit (1–100), before. */
    public static function buildListPayload(array $in): array
    {
        $out = [];
        if (isset($in['recipient']) && trim((string) $in['recipient']) !== '') {
            $out['recipient'] = trim((string) $in['recipient']);
        }
        if (isset($in['reference'])) {
            $ref = CatzConnect::reference($in['reference']);
            if ($ref !== null) {
                $out['reference'] = $ref;
            }
        }
        if (isset($in['broadcast_id'])) {
            if (!self::isUuid($in['broadcast_id'])) {
                throw new \InvalidArgumentException("'broadcast_id' must be a broadcast id (UUID)");
            }
            $out['broadcast_id'] = trim((string) $in['broadcast_id']);
        }
        if (!$out) {
            throw new \InvalidArgumentException("Give 'recipient', 'reference' or 'broadcast_id'");
        }
        if (isset($in['channel'])) {
            $c = strtolower((string) $in['channel']);
            if (!in_array($c, ['email', 'sms', 'whatsapp', 'push', 'voice'], true)) {
                throw new \InvalidArgumentException("'channel' must be email, sms, whatsapp, push or voice");
            }
            $out['channel'] = $c;
        }
        if (isset($in['limit'])) {
            if (!is_int($in['limit']) || $in['limit'] < 1 || $in['limit'] > 100) {
                throw new \InvalidArgumentException("'limit' must be from 1 to 100");
            }
            $out['limit'] = $in['limit'];
        }
        if (isset($in['before'])) {
            $out['before'] = (string) $in['before'];
        }
        return $out;
    }

    /** ['messages' => [...], 'next_before' => ?string]. */
    public function list(array $in): array
    {
        return self::call($this->http, '/sdk/messages/list', self::buildListPayload($in));
    }
}
