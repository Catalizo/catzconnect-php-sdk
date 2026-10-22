<?php

namespace CatzConnect;

use CatzConnect\Core\HttpClient;

/**
 * Management API: templates (with language versions), webhooks,
 * suppressions and API keys of your organization. Needs a live server key
 * with the management scope (panel → API keys) on a plan with the
 * management API. Each method returns the server's `data`.
 *
 *     $client->manage()->call('templates', 'save', ['name' => 'Welcome', 'subject' => 'Hi', 'body' => '<p>…</p>']);
 *     $key = $client->manage()->createKey(['name' => 'CI', 'kind' => 'test', 'channels' => ['Email']]);
 */
class Manage
{
    public const OPERATIONS = [
        'templates' => ['list', 'get', 'save', 'delete', 'variant_save', 'variant_delete'],
        'webhooks' => ['list', 'save', 'delete'],
        'suppressions' => ['list', 'add', 'remove'],
        'keys' => ['list', 'create', 'rotate', 'revoke', 'policy'],
    ];

    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient();
    }

    public function call(string $resource, string $op, array $body = []): array
    {
        if (!in_array($op, self::OPERATIONS[$resource] ?? [], true)) {
            throw new \InvalidArgumentException("Unknown management operation $resource/$op");
        }
        return Messages::call($this->http, "/sdk/manage/$resource/$op", $body);
    }

    public function listTemplates(?string $channel = null): array
    {
        return $this->call('templates', 'list', $channel ? ['channel' => $channel] : []);
    }

    public function saveTemplate(array $t): array
    {
        if (trim((string) ($t['name'] ?? '')) === '') {
            throw new \InvalidArgumentException("Missing 'name'");
        }
        return $this->call('templates', 'save', $t);
    }

    public function saveTemplateVariant(array $v): array
    {
        return $this->call('templates', 'variant_save', $v);
    }

    public function saveWebhook(array $w): array
    {
        if (empty($w['events'])) {
            throw new \InvalidArgumentException('Choose at least one event');
        }
        return $this->call('webhooks', 'save', $w);
    }

    /**
     * $channel: 'email' (address), 'sms' / 'whatsapp' / 'voice' (E.164
     * number) or 'push' (your app's external_user_id). $options: 'detail',
     * 'topic' (email only: just that subscription topic).
     */
    public function addSuppression(string $channel, string $address, array $options = []): array
    {
        $body = ['channel' => $channel, 'address' => $address];
        if (isset($options['detail'])) {
            $body['detail'] = (string) $options['detail'];
        }
        $topic = self::topic($channel, $options['topic'] ?? null);
        if ($topic !== null) {
            $body['topic'] = $topic;
        }
        return $this->call('suppressions', 'add', $body);
    }

    /**
     * Every entry of $address on $channel ($channel may be null for an email
     * address), or only $options['topic']'s.
     */
    public function removeSuppression(string $address, ?string $channel = null, array $options = []): array
    {
        $body = ['address' => $address] + ($channel ? ['channel' => $channel] : []);
        $topic = self::topic($channel ?? (strpos($address, '@') !== false ? 'email' : ''), $options['topic'] ?? null);
        if ($topic !== null) {
            $body['topic'] = $topic;
        }
        return $this->call('suppressions', 'remove', $body);
    }

    /** Remove one entry by its id (from listSuppressions). */
    public function removeSuppressionById(string $id): array
    {
        if (!Messages::isUuid($id)) {
            throw new \InvalidArgumentException("'id' must be a suppression id (UUID)");
        }
        return $this->call('suppressions', 'remove', ['id' => trim($id)]);
    }

    /** $filter: channel, topic, q, limit, offset → ['suppressions' => [...], 'total' => n]. */
    public function listSuppressions(array $filter = []): array
    {
        return $this->call('suppressions', 'list', $filter);
    }

    private static function topic(string $channel, $topic): ?string
    {
        if ($topic === null) {
            return null;
        }
        if (strtolower($channel) !== 'email') {
            throw new \InvalidArgumentException("'topic' applies to email suppressions only");
        }
        if (!is_string($topic) || !preg_match(PushApi::EMAIL_TOPIC_KEY, $topic)) {
            throw new \InvalidArgumentException("'topic' must be a topic key");
        }
        return $topic;
    }

    /** Returns the new key's secrets (api_key, private_key, server_public_key): shown only here. */
    public function createKey(array $k): array
    {
        if (isset($k['kind']) && !in_array($k['kind'], ['server', 'test'], true)) {
            throw new \InvalidArgumentException("'kind' must be server or test");
        }
        return $this->call('keys', 'create', $k);
    }

    public function rotateKey(string $id, bool $rotateEte = false): array
    {
        return $this->call('keys', 'rotate', ['id' => $id, 'rotate_ete' => $rotateEte]);
    }

    public function revokeKey(string $id): array
    {
        return $this->call('keys', 'revoke', ['id' => $id]);
    }
}
