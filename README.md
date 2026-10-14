# CatzConnect PHP SDK

Secure PHP SDK to send encrypted communication requests (Email OTP, etc).

---

## 🚀 Installation

```bash
composer require catzconnect/catzconnect-php


🔐 Environment Setup
CATZCONNECT_API_KEY=your_api_key
CATZCONNECT_PRIVATE_KEY=base64_private_key
CATZCONNECT_SERVER_PUBLIC_KEY=base64_server_public_key

📦 Usage for Otp

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

## WhatsApp

```php
$client->send([
    'type' => 'verification',
    'channel' => 'whatsapp',
    'template' => 'otp',
    'identity' => '919578456444',       // your connected WhatsApp number
    'payload' => [
        'to' => '+91 98765 43210',       // phone number with country code
        'otp' => '123456',
    ],
]);
```

`transactional` / `custom` takes `to`, `body`, and an optional `subject` sent as a
bold first line. OTPs need an approved Authentication template; custom messages
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
