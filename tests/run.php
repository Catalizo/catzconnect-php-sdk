<?php
// Self-contained tests (no PHPUnit): php tests/run.php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'CatzConnect\\') === 0) {
        $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('CatzConnect\\'))) . '.php';
        if (is_file($f)) require $f;
    }
});

use CatzConnect\ApiException;
use CatzConnect\Calls;
use CatzConnect\CatzConnect;
use CatzConnect\Core\HttpClient;
use CatzConnect\Voice;

$failures = 0;
$count = 0;
function check(bool $ok, string $what): void {
    global $failures, $count;
    $count++;
    if (!$ok) { $failures++; echo "FAIL: $what\n"; }
}
function throws(callable $f, string $needle, string $what): void {
    try { $f(); check(false, "$what (no exception)"); }
    catch (\Throwable $e) { check(strpos($e->getMessage(), $needle) !== false, "$what: got '{$e->getMessage()}'"); }
}

// Keys: the SDK is the client; this script plays the server.
$client = sodium_crypto_box_keypair();
$server = sodium_crypto_box_keypair();
putenv('CATZCONNECT_API_KEY=live_php');
putenv('CATZCONNECT_PRIVATE_KEY=' . base64_encode(sodium_crypto_box_secretkey($client)));
putenv('CATZCONNECT_SERVER_PUBLIC_KEY=' . base64_encode(sodium_crypto_box_publickey($server)));
putenv('CATZCONNECT_BASE_URL=https://api.test/');

function serverKey(): string {
    global $client, $server;
    $shared = sodium_crypto_scalarmult(sodium_crypto_box_secretkey($server), sodium_crypto_box_publickey($client));
    return sodium_crypto_generichash(sodium_crypto_generichash($shared, '', 32) . 'CONNECT-@-2026-HS-@-CATZ', '', 32);
}
function serverOpen(array $b): array {
    $p = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(base64_decode($b['ciphertext']), '', base64_decode($b['nonce']), serverKey());
    return json_decode($p, true);
}
function serverSeal($v): array {
    $n = random_bytes(12);
    return ['nonce' => base64_encode($n), 'ciphertext' => base64_encode(sodium_crypto_aead_chacha20poly1305_ietf_encrypt(json_encode($v), '', $n, serverKey()))];
}

class FakeHttp extends HttpClient {
    public array $requests = [];
    public int $status = 200;
    /** @var callable */
    public $reply;
    protected function transport(string $url, array $headers, string $body): array {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => serverOpen(json_decode($body, true))];
        return [$this->status, json_encode(($this->reply)($url))];
    }
}

$call = ['id' => 'c1', 'engine' => 'catz', 'mode' => 'video', 'status' => 'ringing', 'room' => 'c1',
    'participants' => [['user_id' => 'alice', 'role' => 'caller', 'state' => 'joined']], 'created_at' => '2026-10-18T00:00:00Z'];

$http = new FakeHttp();
$http->reply = function (string $url) use ($call) {
    if (str_ends_with($url, '/create')) return ['success' => true, 'call' => $call];
    if (str_ends_with($url, '/get')) return ['success' => true, 'message' => 'ok', 'data' => ['call' => $call]];
    if (str_ends_with($url, '/token')) return ['success' => true, 'data' => serverSeal(['token' => 'ct1.a.b', 'expires_at' => 99, 'ws_url' => 'wss://x/rtc/v1/ws'])];
    if (str_ends_with($url, '/end')) return ['success' => true, 'message' => 'Call ended'];
    return ['success' => true, 'message' => 'Queued', 'data' => ['message_id' => 'v1']];
};
$cc = new CatzConnect($http);

// calls.create
$got = $cc->calls()->create(['mode' => 'video', 'from' => 'alice', 'to' => ['bob'], 'engine' => 'catz', 'ring' => false,
    'metadata' => ['order' => 7], 'maxParticipants' => 4]);
check($got['id'] === 'c1', 'create returns the call');
$r = $http->requests[0];
check($r['url'] === 'https://api.test/sdk/calls/create', 'create url');
check(in_array('Authorization: Bearer live_php', $r['headers'], true), 'bearer header');
check(in_array('Content-Type: application/json', $r['headers'], true), 'content type');
check($r['body']['mode'] === 'video' && $r['body']['to'] === ['bob'] && $r['body']['ring'] === false
    && $r['body']['metadata'] === ['order' => 7] && $r['body']['max_participants'] === 4 && is_int($r['body']['ts']), 'create body');

check($cc->calls()->get('c1')['status'] === 'ringing', 'get reads data.call');
check($http->requests[1]['body']['call_id'] === 'c1', 'get body');
$t = $cc->calls()->token('alice', 600);
check($t === ['token' => 'ct1.a.b', 'expires_at' => 99, 'ws_url' => 'wss://x/rtc/v1/ws'], 'token from an encrypted envelope');
check($http->requests[2]['body']['ttl_seconds'] === 600 && $http->requests[2]['body']['user_id'] === 'alice', 'token body');
check($cc->calls()->end('c1', 'done') === ['success' => true, 'message' => 'Call ended'], 'end');
check($http->requests[3]['body']['reason'] === 'done', 'end reason');

// errors
$http->status = 402;
$http->reply = fn () => ['success' => false, 'message' => 'Your plan has used its call minutes.', 'code' => 'minutes_cap_reached'];
try { $cc->calls()->create(['mode' => 'audio', 'from' => 'a', 'to' => ['b']]); check(false, 'want ApiException'); }
catch (ApiException $e) { check($e->status === 402 && $e->errorCode === 'minutes_cap_reached' && $e->getMessage() === 'Your plan has used its call minutes.', 'api error fields'); }
$http->status = 200;
$http->reply = fn () => ['success' => false, 'message' => 'Calls are not on this plan', 'code' => 'plan_required'];
try { $cc->calls()->token('a'); check(false, 'want ApiException'); }
catch (ApiException $e) { check($e->errorCode === 'plan_required', 'success:false throws'); }

// validation
$ok = ['mode' => 'audio', 'from' => 'a', 'to' => ['b']];
throws(fn () => Calls::buildCreatePayload(['mode' => 'screen'] + $ok), 'mode', 'mode');
throws(fn () => Calls::buildCreatePayload(['from' => ''] + $ok), "Missing 'from'", 'empty from');
throws(fn () => Calls::buildCreatePayload(['from' => str_repeat('x', 129)] + $ok), '128', 'long from');
throws(fn () => Calls::buildCreatePayload(['from' => "a\x01"] + $ok), 'control', 'control chars');
throws(fn () => Calls::buildCreatePayload(['to' => []] + $ok), 'at least one', 'empty to');
throws(fn () => Calls::buildCreatePayload(['to' => array_map(fn ($i) => "u$i", range(1, 50))] + $ok), 'at most 49', '50 callees');
throws(fn () => Calls::buildCreatePayload(['to' => ['b', 'b']] + $ok), 'twice', 'duplicate');
throws(fn () => Calls::buildCreatePayload(['to' => ['a']] + $ok), 'caller', 'from in to');
throws(fn () => Calls::buildCreatePayload(['metadata' => ['x' => str_repeat('y', 4100)]] + $ok), '4 KB', 'metadata size');
throws(fn () => Calls::buildCreatePayload(['metadata' => [1, 2]] + $ok), 'object', 'metadata list');
throws(fn () => Calls::buildCreatePayload(['to' => ['b', 'c'], 'maxParticipants' => 2] + $ok), 'do not fit', 'max participants');
check(count(Calls::buildCreatePayload(['to' => array_map(fn ($i) => "u$i", range(1, 49))] + $ok)['to']) === 49, '49 callees ok');
check(Calls::buildCreatePayload(['from' => str_repeat('é', 128)] + $ok)['from'] === str_repeat('é', 128), '128 multibyte chars ok');
throws(fn () => Calls::buildTokenPayload('a', 59), '60', 'ttl min');
throws(fn () => Calls::buildTokenPayload('a', 86401), '86400', 'ttl max');
check(Calls::buildTokenPayload('a') === ['user_id' => 'a'], 'default ttl');

// Voice
$otp = ['channel' => 'voice', 'type' => 'verification', 'template' => 'otp', 'identity' => '',
    'payload' => ['to' => '+1 (415) 555-0100', 'otp' => '123456', 'data' => ['language' => 'en-US', 'voice' => 'female', 'repeat' => 2]]];
check(CatzConnect::buildPayload($otp) === ['message_type' => 'Verification', 'channel' => 'Voice', 'template' => 'Otp', 'identity' => '',
    'to' => '+14155550100', 'otp' => '123456', 'data' => ['language' => 'en-US', 'voice' => 'female', 'repeat' => '2']], 'voice payload');
$custom = ['channel' => 'Voice', 'type' => 'notification', 'template' => 'custom', 'identity' => '',
    'payload' => ['to' => '+919876543210', 'body' => 'Your order shipped.', 'provider' => 'Twilio main']];
check(CatzConnect::buildPayload($custom)['provider'] === 'Twilio main', 'voice provider');
$bad = function (array $p) use ($custom) { $c = $custom; $c['payload'] = $p + $c['payload']; return fn () => CatzConnect::buildPayload($c); };
throws($bad(['to' => '4155550100']), 'E.164', 'voice e164');
throws($bad(['body' => str_repeat('a', 1001)]), '1000', 'voice length');
throws($bad(['body' => "a\x07"]), 'control', 'voice control');
throws($bad(['data' => ['repeat' => '4']]), 'repeat', 'repeat 4');
throws($bad(['data' => ['repeat' => 0]]), 'repeat', 'repeat 0');
throws($bad(['data' => ['language' => 'english']]), 'language', 'language');
check(is_array(CatzConnect::buildPayload(['payload' => ['body' => str_repeat('é', 1000)] + $custom['payload']] + $custom)), '1000 chars ok');
throws(fn () => CatzConnect::buildPayload(['payload' => ['to' => '+14155550100']] + $otp), "'otp'", 'voice otp missing');

$http->reply = fn () => ['success' => true, 'message' => 'Queued', 'data' => ['message_id' => 'v1']];
$res = $cc->sendVoice($custom);
check($res['data']['message_id'] === 'v1', 'sendVoice');

// Other channels unchanged.
check(CatzConnect::buildPayload(['channel' => 'sms', 'type' => 'verification', 'template' => 'otp', 'identity' => '', 'payload' => ['to' => '+14155550100', 'otp' => '1234']])
    === ['message_type' => 'Verification', 'channel' => 'SMS', 'template' => 'Otp', 'identity' => '', 'to' => '+14155550100', 'otp' => '1234'], 'sms unchanged');

// language
$emailOtp = ['channel' => 'Email', 'type' => 'Verification', 'template' => 'Otp', 'identity' => 'a@b.co', 'payload' => ['to' => 'u@x.co', 'otp' => '123456']];
foreach (['en' => 'en', 'DE' => 'de', 'de-DE' => 'de', 'ja_JP' => 'ja', ' ar-EG ' => 'ar', 'hi-Latn-IN' => 'hi'] as $in => $want) {
    check(\CatzConnect\Language::normalise((string) $in) === $want, "normalise $in");
}
foreach (['fr', 'fr-FR', '', '-', 'de--DE', 'english', 'd e', 7] as $badLang) {
    throws(fn () => \CatzConnect\Language::normalise($badLang), 'en, de, ja, ar, hi', 'bad language ' . var_export($badLang, true));
    check(!\CatzConnect\Language::isValid($badLang), 'isValid false ' . var_export($badLang, true));
}
check(!array_key_exists('language', CatzConnect::buildPayload($emailOtp)), 'language omitted when unset');
check(CatzConnect::buildPayload(['language' => 'de-DE'] + $emailOtp)['language'] === 'de', 'language normalised in payload');
$vl = CatzConnect::buildPayload(['language' => 'ja'] + $otp);
check($vl['language'] === 'ja' && $vl['data']['language'] === 'en-US', 'voice keeps data.language next to language');
check(CatzConnect::buildPayload(['channel' => 'sms', 'type' => 'verification', 'template' => 'otp', 'identity' => '', 'language' => 'HI',
    'payload' => ['to' => '+14155550100', 'otp' => '1234']])['language'] === 'hi', 'sms language');
throws(fn () => CatzConnect::buildPayload(['language' => 'fr'] + $emailOtp), "Unsupported 'language' \"fr\"", 'unsupported language');

$http->requests = [];
$http->reply = fn () => ['success' => true, 'message' => 'ok'];
$cc->send($emailOtp);
check(!array_key_exists('language', $http->requests[0]['body']), 'send without language');
$cl = new CatzConnect($http, 'ar-EG');
check($cl->getDefaultLanguage() === 'ar', 'default normalised');
$cl->send($emailOtp);
check($http->requests[1]['body']['language'] === 'ar', 'default language sent');
$cl->send(['language' => 'ja'] + $emailOtp);
check($http->requests[2]['body']['language'] === 'ja', 'explicit language wins');
$cl->setDefaultLanguage(null)->send($emailOtp);
check(!array_key_exists('language', $http->requests[3]['body']), 'default cleared');
throws(fn () => new CatzConnect($http, 'xx'), 'Unsupported', 'bad default language');
throws(fn () => $cc->send(['language' => 'fr-FR'] + $emailOtp), 'fr-FR', 'send rejects bad language');
check(count($http->requests) === 4, 'bad language never reaches the server');
check(CatzConnect::VERSION === '1.5.0', 'version');

echo ($failures === 0 ? "OK" : "FAILED") . " — $count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
