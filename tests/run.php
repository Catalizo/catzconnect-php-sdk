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
check(CatzConnect::VERSION === '2.0.0', 'version');

// Base URL: https only, plain http to local hosts or with the opt-in.
foreach (['https://api.example.com', 'http://localhost:8080', 'http://127.0.0.1:8080', 'http://[::1]:8080', 'http://10.0.2.2:8080'] as $u) {
    try { HttpClient::checkBaseUrl($u); check(true, $u); } catch (\Throwable $e) { check(false, "$u refused: {$e->getMessage()}"); }
}
throws(fn () => HttpClient::checkBaseUrl('http://api.example.com'), 'plain http://', 'remote http refused');
throws(fn () => HttpClient::checkBaseUrl('http://localhost.example.com'), 'plain http://', 'localhost lookalike refused');
throws(fn () => HttpClient::checkBaseUrl('ftp://api.example.com'), 'must start with https', 'ftp refused');
throws(fn () => HttpClient::checkBaseUrl('api.example.com'), 'not a valid URL', 'garbage refused');
HttpClient::checkBaseUrl('http://api.example.com', true);
$http->requests = [];
putenv('CATZCONNECT_BASE_URL=http://api.example.com');
throws(fn () => $cc->send($emailOtp), 'plain http://', 'send refuses remote http');
check(count($http->requests) === 0, 'nothing sent over http');
$cc->setAllowInsecureHttp(true);
$cc->send($emailOtp);
check(count($http->requests) === 1 && strpos($http->requests[0]['url'], 'http://api.example.com/') === 0, 'opt-in allows http');
$cc->setAllowInsecureHttp(false);
putenv('CATZCONNECT_ALLOW_INSECURE_HTTP=1');
$cc->send($emailOtp);
check(count($http->requests) === 2, 'env opt-in allows http');
putenv('CATZCONNECT_ALLOW_INSECURE_HTTP');
// K8: the opt-in is read trimmed (" 1\n" from a .env line counts) …
putenv("CATZCONNECT_ALLOW_INSECURE_HTTP= 1\n");
$cc->send($emailOtp);
check(count($http->requests) === 3, 'padded env opt-in allows http');
putenv('CATZCONNECT_ALLOW_INSECURE_HTTP');
// … and a blank CATZCONNECT_BASE_URL means the default API.
foreach (['', '   '] as $blank) {
    putenv("CATZCONNECT_BASE_URL=$blank");
    $cc->send($emailOtp);
    $last = end($http->requests)['url'];
    check(strpos($last, 'https://api.catzconnect.com/') === 0, 'blank base URL is the default (' . json_encode($blank) . ')');
}
putenv('CATZCONNECT_BASE_URL=https://api.test/');

// ── Developer API: idempotency, reference, WhatsApp/tracking, messages, OTP, manage, push devices, webhooks ──
$ID = '6f0c1b9e-1111-4222-8333-944455556666';
$http = new FakeHttp();
$cc = new CatzConnect($http);
$http->reply = fn () => ['success' => true, 'message' => 'Success', 'data' => ['message_id' => $ID, 'status' => 'Queued']];
$custom = ['channel' => 'Email', 'type' => 'Transactional', 'template' => 'Custom', 'identity' => 'hello',
    'payload' => ['to' => 'a@b.co', 'subject' => 'Hi', 'body' => 'x', 'tracking' => ['opens' => false]],
    'idempotencyKey' => 'order-42', 'reference' => 'order 42'];
$res = $cc->send($custom);
check($res['data']['message_id'] === $ID, 'send returns data.message_id');
$r = end($http->requests);
check(in_array('Idempotency-Key: order-42', $r['headers'], true), 'idempotency header');
check($r['body']['idempotency_key'] === 'order-42' && $r['body']['reference'] === 'order 42', 'idempotency + reference body');
check($r['body']['tracking'] === ['opens' => false], 'tracking passed');
$plain = $custom; unset($plain['idempotencyKey'], $plain['reference'], $plain['payload']['tracking']);
check(!array_key_exists('idempotency_key', CatzConnect::buildPayload($plain)) && !array_key_exists('reference', CatzConnect::buildPayload($plain)), 'no new fields when unset');
throws(fn () => CatzConnect::buildPayload(['idempotencyKey' => 'has space'] + $custom), 'idempotencyKey', 'bad idempotency key');
throws(fn () => CatzConnect::buildPayload(['reference' => "a\nb"] + $custom), 'reference', 'bad reference');
throws(fn () => CatzConnect::buildPayload(['channel' => 'sms', 'type' => 'transactional', 'template' => 'custom', 'identity' => '',
    'payload' => ['to' => '+15550100', 'body' => 'x', 'dlt_template_id' => '1', 'tracking' => ['opens' => true]]]), 'Email channel only', 'tracking on sms');

$waT = ['channel' => 'WhatsApp', 'type' => 'Transactional', 'template' => 'order_update', 'identity' => '919578456444',
    'payload' => ['to' => '+919876543210', 'whatsapp' => ['language' => 'en_US', 'header' => ['type' => 'text', 'text' => '#1'],
        'body' => ['Ravi', '#1'], 'buttons' => [['index' => 0, 'type' => 'url', 'text' => 'abc']]]]];
check(CatzConnect::buildPayload($waT)['whatsapp']['body'] === ['Ravi', '#1'], 'wa template payload');
$waM = ['channel' => 'WhatsApp', 'type' => 'Notification', 'template' => 'Custom', 'identity' => '919578456444',
    'payload' => ['to' => '+919876543210', 'body' => 'Invoice', 'whatsapp' => ['media' => ['type' => 'document', 'link' => 'https://x.example/i.pdf', 'filename' => 'i.pdf']]]];
check(CatzConnect::buildPayload($waM)['whatsapp']['media']['type'] === 'document', 'wa media payload');
throws(fn () => CatzConnect::buildPayload(['template' => 'Order Update'] + $waT), 'lower-case', 'wa template name');
$m2 = $waM; $m2['payload']['whatsapp']['media'] = ['type' => 'audio', 'link' => 'https://x/a', 'caption' => 'no'];
throws(fn () => CatzConnect::buildPayload($m2), 'audio', 'audio caption');
$m3 = $waM; $m3['payload']['whatsapp']['media'] = ['type' => 'image', 'link' => 'http://x/a'];
throws(fn () => CatzConnect::buildPayload($m3), 'https', 'media https');
$t2 = $waT; $t2['payload']['whatsapp']['body'] = ["two\nlines"];
throws(fn () => CatzConnect::buildPayload($t2), 'new lines', 'body new line');
$t3 = $waT; $t3['payload']['whatsapp']['buttons'] = [['index' => 0, 'type' => 'url']];
throws(fn () => CatzConnect::buildPayload($t3), 'url button', 'url button');

$http->status = 409;
$http->reply = fn () => ['success' => false, 'message' => 'closed', 'code' => 'whatsapp_window_closed'];
try { $cc->send($waM); check(false, 'want ApiException'); }
catch (ApiException $e) { check($e->errorCode === \CatzConnect\ErrorCodes::WHATSAPP_WINDOW_CLOSED && strpos($e->getMessage(), 'HTTP 409: ') === 0, 'send refusal keeps message, adds code'); check($e->getCode() === 0 && $e->status === 409, 'send refusal keeps getCode() 0 as before; status on ->status'); }
$http->status = 200;
check(\CatzConnect\ErrorCodes::isRetryable('sending_paused') && !\CatzConnect\ErrorCodes::isRetryable('key_expired'), 'retryable codes');

$http->reply = fn () => ['success' => true, 'data' => ['message_id' => $ID, 'status' => 'delivered', 'events' => [['type' => 'queued', 'at' => 'x']]]];
check($cc->messages()->get($ID)['status'] === 'delivered', 'messages.get');
check(str_ends_with(end($http->requests)['url'], '/sdk/messages/get') && end($http->requests)['body']['message_id'] === $ID, 'messages.get request');
throws(fn () => $cc->messages()->get('nope'), 'UUID', 'messages.get bad id');
check(\CatzConnect\Messages::buildListPayload(['recipient' => '+15550100', 'channel' => 'SMS', 'limit' => 5]) === ['recipient' => '+15550100', 'channel' => 'sms', 'limit' => 5], 'list payload');
throws(fn () => \CatzConnect\Messages::buildListPayload([]), 'recipient', 'list needs a filter');

check(\CatzConnect\Otp::buildStartPayload(['channel' => 'sms', 'to' => '+15550100', 'code_length' => 8]) === ['channel' => 'SMS', 'to' => '+15550100', 'code_length' => 8], 'otp start payload');
throws(fn () => \CatzConnect\Otp::buildStartPayload(['channel' => 'push', 'to' => 'x']), 'channel', 'otp channel');
throws(fn () => \CatzConnect\Otp::buildStartPayload(['channel' => 'email', 'to' => 'a@b.co', 'ttl_seconds' => 5]), 'ttl_seconds', 'otp ttl');
check(\CatzConnect\Otp::buildVerifyPayload(['verification_id' => $ID, 'code' => '123 456']) === ['verification_id' => $ID, 'code' => '123456'], 'otp verify payload');
$http->status = 400;
$http->reply = fn () => ['success' => false, 'message' => 'wrong', 'code' => 'otp_invalid', 'attempts_remaining' => 4];
try { $cc->otp()->verify(['verification_id' => $ID, 'code' => '000000']); check(false, 'want ApiException'); }
catch (ApiException $e) { check($e->errorCode === 'otp_invalid' && $e->details['attempts_remaining'] === 4, 'otp_invalid details'); }
$http->status = 200;

$http->reply = fn () => ['success' => true, 'data' => ['id' => $ID, 'api_key' => 'k', 'private_key' => 'p', 'server_public_key' => 's']];
check($cc->manage()->createKey(['name' => 'CI', 'kind' => 'test', 'channels' => ['Email']])['api_key'] === 'k', 'manage createKey');
check(str_ends_with(end($http->requests)['url'], '/sdk/manage/keys/create'), 'manage url');
throws(fn () => $cc->manage()->call('keys', 'explode'), 'Unknown management operation', 'unknown op');
throws(fn () => $cc->manage()->createKey(['name' => 'x', 'kind' => 'app']), 'server or test', 'app kind');

class FakePublicHttp extends HttpClient {
    public array $requests = [];
    protected function transport(string $url, array $headers, string $body): array {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
        return [200, json_encode(['success' => true, 'message' => 'ok', 'data' => ['device_id' => 'd1', 'created' => true]])];
    }
}
$ph = new FakePublicHttp();
$pd = (new CatzConnect($ph))->pushDevices();
$tok = str_repeat('t', 40);
check($pd->register(['publishable_key' => 'pk_push_abcdef', 'token' => $tok, 'platform' => 'web', 'user_hash' => 'h'])['device_id'] === 'd1', 'push register');
$pr = $ph->requests[0];
check($pr['url'] === 'https://api.test/push/register' && $pr['body']['user_hash'] === 'h', 'push register request');
check(count(array_filter($pr['headers'], fn ($h) => stripos($h, 'Authorization') === 0)) === 0, 'no API key on public calls');
throws(fn () => \CatzConnect\PushDevices::buildRegisterPayload(['publishable_key' => 'pk_push_abcdef', 'token' => $tok, 'platform' => 'tv']), 'platform', 'push platform');

$body = json_encode(['id' => 'e1', 'type' => 'sms.delivered', 'created_at' => 'x', 'organization_id' => 'o', 'test' => true, 'data' => ['message_id' => 'm']]);
$sig = hash_hmac('sha256', '1700000000.' . $body, 'whsec_test');
$ev = \CatzConnect\Webhook::parseEvent($body, "t=1700000000,v1=$sig", 'whsec_test', 300, 1700000000);
check($ev['type'] === 'sms.delivered' && $ev['test'] === true && $ev['data']['message_id'] === 'm', 'parseEvent');
throws(fn () => \CatzConnect\Webhook::parseEvent($body, "t=1700000000,v1=$sig", 'other', 300, 1700000000), 'signature', 'parseEvent bad secret');
check(in_array('whatsapp.read', \CatzConnect\Webhook::EVENTS, true), 'events list');

// ─── cross-channel: email topics, push broadcasts / topics, suppressions per channel ──
$em = ['channel' => 'Email', 'type' => 'Transactional', 'template' => 'Custom', 'identity' => 'h',
    'payload' => ['to' => 'a@b.co', 'subject' => 's', 'body' => 'b', 'topic' => 'newsletter']];
check(CatzConnect::buildPayload($em)['topic'] === 'newsletter', 'email topic sent');
$em2 = $em; $em2['payload']['topic'] = 'News Letter';
throws(fn () => CatzConnect::buildPayload($em2), 'topic key', 'bad email topic');
throws(fn () => CatzConnect::buildPayload(['channel' => 'SMS', 'type' => 'Transactional', 'template' => 'Custom', 'identity' => '',
    'payload' => ['to' => '+15550100', 'body' => 'x', 'topic' => 'newsletter']]), 'Email channel only', 'topic on sms');
$pu = fn (array $p) => ['channel' => 'Push', 'type' => 'Notification', 'template' => 'Notification', 'identity' => 'my-firebase', 'payload' => $p];
$pb = CatzConnect::buildPayload($pu(['body' => 'Sale', 'push' => ['topic' => 'deals', 'send_at' => new DateTimeImmutable('2026-11-02T11:00:00+02:00')]]));
check($pb['push'] === ['topic' => 'deals', 'send_at' => '2026-11-02T09:00:00Z'] && !isset($pb['to']), 'push topic + DateTime send_at');
check(CatzConnect::buildPayload($pu(['body' => 'x', 'push' => ['broadcast' => true]]))['push'] === ['broadcast' => true], 'push broadcast');
$tok = str_repeat('t', 40);
check(CatzConnect::buildPayload($pu(['to' => $tok, 'body' => 'x', 'push' => ['send_at' => '2026-11-02T09:00:00Z']]))['to'] === $tok, 'scheduled single token keeps to');
throws(fn () => CatzConnect::buildPayload($pu(['to' => $tok, 'body' => 'x', 'push' => ['broadcast' => true]])), "leave 'to'", 'broadcast with to');
throws(fn () => CatzConnect::buildPayload($pu(['body' => 'x', 'push' => ['broadcast' => true, 'topic' => 'a']])), 'not both', 'broadcast and topic');
throws(fn () => CatzConnect::buildPayload($pu(['body' => 'x', 'push' => ['topic' => 'a/b']])), 'push.topic', 'bad push topic');
throws(fn () => CatzConnect::buildPayload($pu(['body' => 'x', 'push' => ['broadcast' => true, 'send_at' => '2026-11-02 09:00']])), 'RFC 3339', 'bad send_at');
$em3 = $em; unset($em3['payload']['topic']); $em3['payload']['push'] = ['broadcast' => true];
throws(fn () => CatzConnect::buildPayload($em3), 'Push channel only', 'push on email');
foreach (['INVALID_TOPIC', 'TOPIC_EMAIL_ONLY', 'RECIPIENT_SUPPRESSED', 'NOT_CANCELABLE', 'NOT_RESENDABLE', 'WHATSAPP_OPTED_OUT', 'CONTENT_GONE'] as $c) {
    check(defined('\CatzConnect\ErrorCodes::' . $c), "error code $c");
}

$http->reply = fn ($url) => str_ends_with($url, '/sdk/push/broadcast')
    ? ['success' => true, 'data' => ['id' => $ID, 'status' => 'dispatching', 'queued' => 500]]
    : ['success' => true, 'data' => ['broadcast_id' => $ID, 'status' => 'canceled', 'topic' => 'deals', 'devices' => 2, 'topics' => []]];
check($cc->push()->broadcast($ID)['queued'] === 500 && str_ends_with(end($http->requests)['url'], '/sdk/push/broadcast'), 'push broadcast');
check($cc->push()->cancel($ID)['status'] === 'canceled' && end($http->requests)['body']['broadcast_id'] === $ID, 'push cancel');
throws(fn () => $cc->push()->cancel('x'), 'UUID', 'push cancel bad id');
check($cc->push()->subscribe(['identity' => 'my-firebase', 'topic' => 'deals', 'tokens' => [$tok], 'external_user_id' => 'u1'])['devices'] === 2, 'push subscribe');
$lb = end($http->requests);
check(str_ends_with($lb['url'], '/sdk/push/topic/subscribe') && $lb['body']['external_user_id'] === 'u1' && $lb['body']['tokens'] === [$tok], 'push subscribe request');
$cc->push()->unsubscribe(['identity' => 'my-firebase', 'topic' => 'deals', 'external_user_id' => 'u1']);
check(str_ends_with(end($http->requests)['url'], '/sdk/push/topic/unsubscribe'), 'push unsubscribe');
throws(fn () => \CatzConnect\PushApi::buildTopicChangePayload(['identity' => 'p', 'topic' => 'deals']), 'tokens', 'topic change needs devices');
$cc->push()->topicsOf('my-firebase');
check(str_ends_with(end($http->requests)['url'], '/sdk/push/topics'), 'push topics');
check(\CatzConnect\Messages::buildListPayload(['broadcast_id' => $ID]) === ['broadcast_id' => $ID], 'list by broadcast');

$http->reply = fn () => ['success' => true, 'data' => []];
$cc->manage()->addSuppression('email', 'a@b.co', ['topic' => 'newsletter', 'detail' => 'asked']);
$lb = end($http->requests)['body'];
check($lb['topic'] === 'newsletter' && $lb['detail'] === 'asked' && $lb['channel'] === 'email', 'suppression add with topic');
throws(fn () => $cc->manage()->addSuppression('sms', '+15550100', ['topic' => 'newsletter']), 'email suppressions only', 'sms topic');
$cc->manage()->addSuppression('whatsapp', '+919876543210');
check(end($http->requests)['body']['channel'] === 'whatsapp', 'whatsapp suppression');
$cc->manage()->removeSuppressionById($ID);
check(end($http->requests)['body']['id'] === $ID, 'remove by id');
$cc->manage()->removeSuppression('user-42', 'push');
$lb = end($http->requests)['body'];
check($lb['address'] === 'user-42' && $lb['channel'] === 'push' && !isset($lb['topic']), 'remove push suppression');

$ph2 = new FakePublicHttp();
$pd2 = (new CatzConnect($ph2))->pushDevices();
$pd2->subscribeTopics('pk_push_abcdef', $tok, ['deals', 'news']);
check($ph2->requests[0]['url'] === 'https://api.test/push/topics/subscribe' && $ph2->requests[0]['body']['topics'] === ['deals', 'news'], 'app topic subscribe');
$pd2->unsubscribeTopics('pk_push_abcdef', $tok, ['deals']);
check($ph2->requests[1]['url'] === 'https://api.test/push/topics/unsubscribe', 'app topic unsubscribe');
throws(fn () => $pd2->subscribeTopics('pk_push_abcdef', $tok, []), 'topics', 'app topics empty');
$openId = '6f0c1b9e-1111-4222-8333-944455556666';
$pd2->opened('pk_push_abcdef', $openId, $tok);
check($ph2->requests[2]['url'] === 'https://api.test/push/opened' && $ph2->requests[2]['body']['token'] === $tok, 'push opened with token');
$pd2->opened('pk_push_abcdef', $openId);
check(!isset($ph2->requests[3]['body']['token']) && $ph2->requests[3]['body']['message_id'] === $openId, 'push opened legacy (no token)');
throws(fn () => $pd2->opened('pk_push_abcdef', $openId, 'short'), 'token', 'push opened short token');

foreach (['email.complained', 'email.unsubscribed', 'push.opened', 'sms.inbound', 'sms.opt_out', 'whatsapp.opt_out'] as $e) {
    check(in_array($e, \CatzConnect\Webhook::EVENTS, true), "event $e");
}

echo ($failures === 0 ? "OK" : "FAILED") . " — $count checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
