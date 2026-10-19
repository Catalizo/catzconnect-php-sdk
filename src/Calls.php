<?php

namespace CatzConnect;

use CatzConnect\Core\Crypto;
use CatzConnect\Core\HttpClient;

/**
 * Voice & Video calls from your backend: create a call, mint a user token
 * for the browser/app client, read a call, end it. Every request uses the
 * same API key, encryption and headers as send().
 *
 *     $call  = $client->calls()->create(['mode' => 'video', 'from' => 'alice', 'to' => ['bob']]);
 *     $token = $client->calls()->token('alice');   // ['token' => 'ct1…', 'expires_at' => …, 'ws_url' => …]
 *
 * Refusals throw ApiException with ->status and ->errorCode.
 */
class Calls
{
    public const MAX_CALLEES = 49;
    public const USER_ID_MAX = 128;
    public const METADATA_MAX_BYTES = 4096;
    public const TOKEN_TTL_MIN = 60;
    public const TOKEN_TTL_MAX = 86400;

    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient();
    }

    /** Throws unless $id is non-empty, at most 128 characters, with no control characters. */
    public static function validateUserId($id, string $field = 'user id'): string
    {
        if (!is_string($id) || trim($id) === '') {
            throw new \Exception("Missing $field");
        }
        $chars = preg_match_all('/./su', $id);
        if ($chars === false) {
            throw new \Exception("$field must be UTF-8");
        }
        if ($chars > self::USER_ID_MAX) {
            throw new \Exception("$field must be at most " . self::USER_ID_MAX . ' characters');
        }
        if (preg_match('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', $id)) {
            throw new \Exception("$field contains control characters");
        }
        return $id;
    }

    private static function callId($id): string
    {
        if (!is_string($id) || trim($id) === '') {
            throw new \Exception('Missing call id');
        }
        $id = trim($id);
        if (strlen($id) > 128 || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new \Exception('Invalid call id');
        }
        return $id;
    }

    /**
     * The JSON body (before encryption) create() posts, after the server's
     * checks. Keys: mode, from, to, engine?, ring?, metadata?,
     * maxParticipants? (max_participants is accepted too).
     */
    public static function buildCreatePayload(array $in): array
    {
        $mode = $in['mode'] ?? null;
        if ($mode !== 'audio' && $mode !== 'video') {
            throw new \Exception("'mode' must be \"audio\" or \"video\"");
        }
        $from = self::validateUserId($in['from'] ?? null, "'from'");
        $to = $in['to'] ?? null;
        if (!is_array($to) || count($to) === 0) {
            throw new \Exception("'to' must list at least one user id");
        }
        if (count($to) > self::MAX_CALLEES) {
            throw new \Exception("'to' can list at most " . self::MAX_CALLEES . ' user ids');
        }
        $seen = [];
        foreach ($to as $u) {
            self::validateUserId($u, "'to' entry");
            if ($u === $from) {
                throw new \Exception("'to' must not include the caller ('from')");
            }
            if (isset($seen[$u])) {
                throw new \Exception("'to' lists '$u' twice");
            }
            $seen[$u] = true;
        }

        $out = ['mode' => $mode, 'from' => $from, 'to' => array_values($to)];

        if (isset($in['engine'])) {
            if (!is_string($in['engine']) || trim($in['engine']) === '') {
                throw new \Exception("'engine' must be \"auto\", \"catz\" or a provider id or label");
            }
            $out['engine'] = trim($in['engine']);
        }
        if (isset($in['ring'])) {
            if (!is_bool($in['ring'])) {
                throw new \Exception("'ring' must be a boolean");
            }
            $out['ring'] = $in['ring'];
        }
        if (isset($in['metadata'])) {
            $m = $in['metadata'];
            if (!is_array($m) || (count($m) > 0 && array_keys($m) === range(0, count($m) - 1))) {
                throw new \Exception("'metadata' must be a JSON object (an associative array)");
            }
            $json = json_encode((object) $m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \Exception("'metadata' must be serialisable as JSON");
            }
            if (strlen($json) > self::METADATA_MAX_BYTES) {
                throw new \Exception("'metadata' must be at most 4 KB as JSON");
            }
            $out['metadata'] = (object) $m;
        }
        $max = $in['maxParticipants'] ?? $in['max_participants'] ?? null;
        if ($max !== null) {
            if (!is_int($max) || $max < 2 || $max > self::MAX_CALLEES + 1) {
                throw new \Exception("'maxParticipants' must be a whole number from 2 to " . (self::MAX_CALLEES + 1));
            }
            if (count($to) + 1 > $max) {
                throw new \Exception((count($to) + 1) . " participants do not fit 'maxParticipants' $max");
            }
            $out['max_participants'] = $max;
        }
        return $out;
    }

    /** The JSON body token() posts. A null ttl leaves the server default (3600). */
    public static function buildTokenPayload(string $userId, ?int $ttlSeconds = null): array
    {
        $out = ['user_id' => self::validateUserId($userId, "'userId'")];
        if ($ttlSeconds !== null) {
            if ($ttlSeconds < self::TOKEN_TTL_MIN || $ttlSeconds > self::TOKEN_TTL_MAX) {
                throw new \Exception("'ttlSeconds' must be from " . self::TOKEN_TTL_MIN . ' to ' . self::TOKEN_TTL_MAX);
            }
            $out['ttl_seconds'] = $ttlSeconds;
        }
        return $out;
    }

    private function post(string $path, array $body): array
    {
        $res = $this->http->request($path, Crypto::encrypt($body));
        return Crypto::openResponse($res);
    }

    /** A field at the top level ({success, call}) or under data. */
    private static function field(array $res, string $name)
    {
        if (array_key_exists($name, $res)) {
            return $res[$name];
        }
        if (isset($res['data']) && is_array($res['data']) && array_key_exists($name, $res['data'])) {
            return $res['data'][$name];
        }
        return null;
    }

    private static function call(array $res): array
    {
        $call = self::field($res, 'call');
        if (!is_array($call) || !isset($call['id'])) {
            throw new \Exception('Unexpected response: no call');
        }
        $call['participants'] = $call['participants'] ?? [];
        return $call;
    }

    /**
     * Creates a call and (unless 'ring' => false) rings the callees.
     * Returns the call: id, engine, provider (null for catz, else
     * ['id' => row id, 'label' => panel label, 'name' => registry name]), mode, status, room,
     * participants [{user_id, role, state}], created_at.
     */
    public function create(array $input): array
    {
        return self::call($this->post('/sdk/calls/create', self::buildCreatePayload($input)));
    }

    /** A user token for the browser/app: ['token', 'expires_at', 'ws_url']. TTL 60–86400 s. */
    public function token(string $userId, ?int $ttlSeconds = null): array
    {
        $res = $this->post('/sdk/calls/token', self::buildTokenPayload($userId, $ttlSeconds));
        $token = self::field($res, 'token');
        if (!is_string($token) || $token === '') {
            throw new \Exception('Unexpected response: no token');
        }
        return [
            'token' => $token,
            'expires_at' => self::field($res, 'expires_at'),
            'ws_url' => (string) (self::field($res, 'ws_url') ?? ''),
        ];
    }

    /** The call with participants (joined_at, left_at, seconds, connection), duration_secs, billed_minutes, end_reason. */
    public function get(string $callId): array
    {
        return self::call($this->post('/sdk/calls/get', ['call_id' => self::callId($callId)]));
    }

    /** Ends a call for everyone. Returns ['success' => true, 'message' => ...]. */
    public function end(string $callId, ?string $reason = null): array
    {
        $body = ['call_id' => self::callId($callId)];
        if ($reason !== null && trim($reason) !== '') {
            $body['reason'] = trim($reason);
        }
        $res = $this->post('/sdk/calls/end', $body);
        return ['success' => ($res['success'] ?? true) !== false, 'message' => (string) ($res['message'] ?? '')];
    }
}
