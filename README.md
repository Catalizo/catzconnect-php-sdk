# CatzConnect PHP SDK

Secure PHP SDK to send encrypted communication requests (Email OTP, etc).

---

## 🚀 Installation

```bash
composer require "catzconnect/catzconnect-php:^2.0"
```

or in `composer.json`:

```json
{
  "require": {
    "catzconnect/catzconnect-php": "^2.0"
  }
}
```

## 🔐 Environment Setup

```env
CATZCONNECT_API_KEY=your_api_key
CATZCONNECT_PRIVATE_KEY=base64_private_key
CATZCONNECT_SERVER_PUBLIC_KEY=base64_server_public_key
```

## 📦 Usage for Otp

```php
use CatzConnect\CatzConnect;

$client = new CatzConnect();

$response = $client->send([
    'type' => 'verification',
    'channel' => 'email',
    'template' => 'otp',
    'identity' => 'user@domain.com',
    'payload' => [
        'to' => 'user@example.com',
        'otp' => '123456'  // a string: an integer would drop leading zeros
    ]
]);

print_r($response);
```

## 🌐 Language of the default OTP templates

An optional top-level `'language'` (`en`, `de`, `ja`, `ar` or `hi`) picks the
language of the **built-in** Otp/Verification templates for Email, SMS,
Voice and WhatsApp. A BCP-47 tag such as `'de-DE'` is accepted and sent as
`'de'`; anything else throws `InvalidArgumentException` (`Unsupported
'language' "fr": use one of en, de, ja, ar, hi …`) before any request.
Without it the body is unchanged and the server uses English. Templates you
created in the panel are not affected.

```php
$client->send([
    'type' => 'verification', 'channel' => 'email', 'template' => 'otp',
    'identity' => 'user@domain.com',
    'language' => 'de-DE',               // sent as "language": "de"
    'payload' => ['to' => 'user@example.com', 'otp' => '123456'],
]);

// A client whose sends default to Japanese when 'language' is not given.
$client = new CatzConnect(null, 'ja');   // or ->setDefaultLanguage('ja'); null clears it
```

`CatzConnect\Language::normalise('de-DE')` returns `'de'`;
`Language::isValid()` and `Language::SUPPORTED` are there too.


📦 Usage for Custom

use CatzConnect\CatzConnect;

$client = new CatzConnect();

$response = $client->send([
    'type' => 'transactional',
    'channel' => 'email',
    'template' => 'custom',
    'identity' => 'user@domain.com',
    'payload' => [
        'to' => 'user@example.com',
        'subject' => 'hello world',
        'body' => 'welcome to catzconnect',
    ]
]);

print_r($response);

## Configuration

Set these in your environment or `.env`:

```bash
CATZCONNECT_API_KEY=your_api_key
CATZCONNECT_PRIVATE_KEY=your_private_key
CATZCONNECT_SERVER_PUBLIC_KEY=your_server_public_key
```

With vlucas/phpdotenv, the standard `Dotenv::createImmutable(__DIR__)->load()`
works — the SDK reads `$_ENV` and `$_SERVER` as well as `getenv()`. Values are
trimmed, so a trailing newline in a key is harmless.

`CATZCONNECT_BASE_URL` (optional, default `https://api.catzconnect.com`) must be
`https://`. Plain `http://` is accepted only for `localhost`, `127.0.0.1`,
`[::1]` and `10.0.2.2` (local development); any other `http://` URL throws an
`InvalidArgumentException` before anything is sent, since the API key would
travel unencrypted. To allow it anyway set `CATZCONNECT_ALLOW_INSECURE_HTTP=1`
or call `$catz->setAllowInsecureHttp(true)`.

## WhatsApp

```php
$client->send([
    'type' => 'verification',
    'channel' => 'whatsapp',
    'template' => 'otp',
    'identity' => '919578456444',       // your connected WhatsApp number
    'language' => 'hi',                  // optional: the template language to ask Meta for
    'payload' => [
        'to' => '+91 98765 43210',       // phone number with country code
        'otp' => '123456',
    ],
]);
```

`transactional` / `custom` takes `to`, `body`, and an optional `subject` sent as a
bold first line. With `'language'` the server sends Meta's matching template
language code — the Authentication template must be approved in that
language on your WhatsApp Business Account, or Meta rejects the message.
OTPs need an approved Authentication template; custom messages
only reach people who messaged your number in the last 24 hours.

## Push notifications (end-to-end encrypted)

```php
$client->send([
    'type' => 'notification',
    'channel' => 'push',
    'template' => 'notification',
    'identity' => 'your-firebase-project-id',
    'payload' => [
        'to' => $fcmToken,
        'device_key' => $devicePublicKey,  // seals the content to that device
        'title' => 'Order shipped',
        'body' => 'Arriving Friday',
        'data' => ['order' => '42'],       // string values only
    ],
]);
```

Casing doesn't matter — `'push'` and `'Push'` both work. The device generates its
keys and decrypts — see `PUSH.md`.

## Email templates from the panel

```php
$client->send([
    'type' => 'transactional',
    'channel' => 'email',
    'template' => 'Order shipped',          // the template's name in the panel
    'identity' => 'noreply@yourdomain.com',
    'payload' => [
        'to' => 'user@example.com',
        'data' => ['name' => 'Ann', 'order_id' => 'A-1042'],
    ],
]);
```

## Push to a user's registered devices

Use `'external_user_id' => 'user-42'` instead of `'to'` to send to every device
your app registered for that user with `POST /push/register`. `device_key` is
not allowed with it; each device's registered key is used.

## Verifying webhooks

```php
$ok = \CatzConnect\Webhook::verify(
    file_get_contents('php://input'),
    $_SERVER['HTTP_CATZ_SIGNATURE'] ?? '',
    getenv('CATZCONNECT_WEBHOOK_SECRET')
);
if (!$ok) { http_response_code(400); exit; }
```

## SMS

`to` is an E.164 number (`+` and the country code; spaces, dashes, dots and
brackets are removed). `identity` is not needed. `sendSms()` returns an
`SmsResult` (`messageId`, `segments`, `encoding`); `send()` with
`'channel' => 'sms'` returns the raw array as before.

```php
// One-time code: verification + otp (a string of up to 15 letters or digits).
$sms = $client->sendSms([
    'type' => 'verification',
    'template' => 'otp',
    'payload' => ['to' => '+14155550100', 'otp' => '123456'],
]);
echo $sms->messageId, ' ', $sms->segments, ' ', $sms->encoding; // … 1 gsm7

// The default OTP text in German ('de-DE' is sent as 'de').
$client->sendSms([
    'type' => 'verification', 'template' => 'otp', 'language' => 'de-DE',
    'payload' => ['to' => '+4915112345678', 'otp' => '123456'],
]);

// Free text: transactional or promotional + custom. India needs a DLT id.
$client->sendSms([
    'type' => 'promotional',
    'template' => 'custom',
    'payload' => [
        'to' => '+91 98765 43210',
        'body' => 'Sale ends tonight',
        'dlt_template_id' => '1207161234567890123',
        'gateway' => 'MSG91 India',   // optional: one of the key's gateways (id or label)
        'sender' => 'CATZIN',         // optional: only with gateway, when it allows overrides
    ],
]);

// An SMS template from the panel, filled from data.
$client->sendSms([
    'type' => 'transactional',
    'template' => 'Order shipped',
    'payload' => ['to' => '+14155550100', 'data' => ['name' => 'Ann']],
]);
```

Validation matches the server: E.164 `to`; `otp` only with `verification`,
`custom` only with `transactional`/`promotional`; `sender` only with `gateway`;
numeric `dlt_template_id`; custom bodies at most 10 segments (1,530 GSM-7 /
670 UCS-2 characters) and a DLT id for +91. `CatzConnect\Sms::analyse($text)`
reports the encoding and segments; `Sms::normaliseE164()` normalises a number.

## Phone calls (Voice)

Channel `voice` places a phone call that speaks the message. `to` is
E.164; `identity` is ignored.

```php
// A one-time code, read out digit by digit.
$res = $client->sendVoice([
    'type' => 'verification',
    'template' => 'otp',
    'identity' => '',
    'payload' => [
        'to' => '+14155550100',
        'otp' => '482913',
        'data' => ['language' => 'en-US', 'voice' => 'female', 'repeat' => '2'],
    ],
]);
echo $res['data']['message_id']; // voice.* webhook events carry it

// The default OTP script in Japanese; TTS language and voice follow it.
// data.language, when given, still overrides the spoken language.
$client->sendVoice([
    'type' => 'verification', 'template' => 'otp', 'identity' => '', 'language' => 'ja',
    'payload' => ['to' => '+819012345678', 'otp' => '482913'],
]);

// Free text (at most 1,000 characters) through one of the key's phone providers.
$client->send([
    'channel' => 'voice', 'type' => 'notification', 'template' => 'custom', 'identity' => '',
    'payload' => ['to' => '+919876543210', 'body' => 'Your table is ready.', 'provider' => 'Exotel India'],
]);
```

Checked before sending: E.164 `to`; `otp` needs `otp` (up to 15
letters/digits); `custom` needs `body` (1–1,000 characters, no control
characters); `data.language` like `en-US`; `data.voice` `female`, `male` or
a provider voice; `data.repeat` 1–3. Any other template name is a Voice
template from the panel, filled from `data`. `CatzConnect\Voice` has the
helpers (`validateText`, `isValidLanguage`, …).

## Voice & Video calls

Calls between your app's users. Your backend creates the call and a
short-lived user token; the browser (or app) connects with the token.
Requests use the same key, encryption and headers as `send()`.

```php
$calls = $client->calls();

$call = $calls->create([
    'mode' => 'video',           // or 'audio'
    'from' => 'alice',           // your user ids, ≤ 128 characters
    'to'   => ['bob'],           // 1–49, unique, not including 'from'
    // 'engine' => 'auto' | 'catz' | '<provider id or label>',
    // 'ring' => true, 'metadata' => ['order' => 'A-1042'], 'maxParticipants' => 4,
]);

$token = $calls->token('alice', 3600); // ['token' => 'ct1…', 'expires_at' => …, 'ws_url' => 'wss://…']
// give $token['token'] and $token['ws_url'] to alice's browser

$detail = $calls->get($call['id']);   // participants, duration_secs, billed_minutes, end_reason
$calls->end($call['id'], 'support_closed');
```

Refusals throw `CatzConnect\ApiException` with `->status`, the server's
message and `->errorCode` (`plan_required`, `minutes_cap_reached`,
`engine_unavailable`, `too_many_participants`, `provider_not_allowed`):

```php
try {
    $calls->create(['mode' => 'audio', 'from' => 'a', 'to' => ['b']]);
} catch (\CatzConnect\ApiException $e) {
    if ($e->errorCode === 'minutes_cap_reached') { /* … */ }
}
```

Inputs are checked first, as the server does: mode, user ids (non-empty,
≤ 128 characters, no control characters), 1–49 unique callees without the
caller, metadata ≤ 4 KB of JSON, TTL 60–86400.

### In the browser

Hand the token (never the API key) to the user's page and use the
TypeScript SDK's browser entry, `catzconnect/call` (no dependencies; native
WebRTC):

```ts
import { CatzCallClient } from "catzconnect/call";

const client = new CatzCallClient({ getToken: () => fetch("/call-token").then((r) => r.text()), url: wsUrl });
client.on("incoming", ({ callId }) => client.accept(callId));
client.on("track", ({ pid, stream }) => (videoFor(pid).srcObject = stream));
client.on("local-stream", ({ stream }) => (selfView.srcObject = stream));
await client.connect();
await client.join(callId, { audio: true, video: true });
```

It keeps the call up by itself: direct path first, relay fallback (UDP →
TCP → TLS), ICE restart on failure or network change, WebSocket reconnect
with resume, token renewal through `getToken`. See the TypeScript README for
every event and method, and a React example.

**Provider hand-off.** When the call runs on a provider engine (Agora,
Twilio Video, LiveKit, …), the browser client emits `provider` with
`{ name, room, token, app_id?, url?, extra? }` instead of opening peer
connections; join with that provider's own SDK.

## Tests

```bash
php tests/run.php
```

## Message status, retry-safe sends, test keys, hosted OTP (2.0.0)

```php
$res = $client->send([... , 'idempotencyKey' => "ship-$orderId", 'reference' => "order-$orderId"]);
$msg = $client->messages()->get($res['data']['message_id']);          // status + events
$s   = $client->otp()->start(['channel' => 'SMS', 'to' => '+919876543210']);
try {
    $client->otp()->verify(['verification_id' => $s['verification_id'], 'code' => $typed]);
} catch (\CatzConnect\ApiException $e) {
    if ($e->errorCode === \CatzConnect\ErrorCodes::OTP_INVALID) { $left = $e->details['attempts_remaining']; }
}
```

WhatsApp approved templates: `'template' => 'order_update', 'payload' => ['to' => …,
'whatsapp' => ['language' => 'en_US', 'body' => ['Ravi']]]`; media: template
`Custom` with `'whatsapp' => ['media' => [...]]`. Test keys are validated but
never delivered or billed. Management API: `$client->manage()->call('webhooks',
'save', [...])` with a key that has the management scope. Webhooks:
`\CatzConnect\Webhook::parseEvent($raw, $header, $secret)['test']`.

## Email topics, push broadcasts and topics (2.0.0)

```php
// Email under a subscription topic: its opt-outs apply.
$client->send(['channel' => 'Email', 'type' => 'Transactional', 'template' => 'Custom', 'identity' => 'news',
    'payload' => ['to' => 'ana@example.com', 'subject' => 'October', 'body' => '<p>…</p>', 'topic' => 'newsletter']]);

// Push to a topic, later (server key).
$res = $client->send(['channel' => 'Push', 'type' => 'Notification', 'template' => 'Notification', 'identity' => 'my-firebase',
    'payload' => ['body' => 'Today only', 'push' => ['topic' => 'deals', 'send_at' => new DateTimeImmutable('2026-11-02 09:00 UTC')]]]);
$client->push()->broadcast($res['data']['broadcast_id']);   // status, queued, over_quota…
$client->push()->subscribe(['identity' => 'my-firebase', 'topic' => 'deals', 'external_user_id' => 'u1']);
$client->pushDevices()->subscribeTopics($publishableKey, $fcmToken, ['deals']);   // in the app
$client->manage()->addSuppression('whatsapp', '+919876543210');
$client->manage()->addSuppression('email', 'ana@example.com', ['topic' => 'newsletter']);
```

App and test keys are refused for broadcasts / topics. `Webhook::EVENTS` includes
`email.complained`, `email.unsubscribed`, `push.opened`, `sms.inbound`,
`sms.opt_out`, `whatsapp.opt_out`.
