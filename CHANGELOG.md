# Changelog

## 2.0.0 — 2026-10-22

The first release since 1.5.0. It collects the entries below that were never published separately (1.7.0, 1.6.0); read them for the details. Every Catz Connect SDK (TypeScript, Go, PHP, Rust, Dart/Flutter) is released as 2.0.0 together.

**Highlights**

- Message status (`messages()`), retry-safe sends (`idempotencyKey`), `reference`, test keys, hosted OTP (`otp()`), the management API (`manage()`) and the push device registry (`pushDevices()`).
- WhatsApp approved templates and media; Email topics and tracking; push broadcasts, topics and scheduled pushes (`push()`); cross-channel opt-outs.
- Security options: https-only base URLs (`setAllowInsecureHttp(true)` / `CATZCONNECT_ALLOW_INSECURE_HTTP=1` to opt out), `HttpClient::checkBaseUrl()`.
- `CatzConnect::VERSION` is `2.0.0`.

**Upgrading from 1.5.x**

- `send()` refusals are `ApiException` (still an `\Exception` with the message `HTTP <status>: <body>` and `getCode()` `0`); read the status from `->status` and the API code from `->errorCode`.
- **https only** (since the 1.6.0 entry): a plain `http://` base URL to a host other than `localhost`, `127.0.0.1`, `::1` or `10.0.2.2` is refused before a request is sent; opt in to plain http for a test server only.
- The API answers a request whose timestamp is more than 30 s off with HTTP 400 `clock_skew` (was 401): keep the sending host's clock in sync (NTP).
- Everything else is backward compatible: existing sends post the same body.

## 1.7.0 (unreleased, included in 2.0.0)

**Message status, retry-safe sends, test keys, hosted OTP, management API, push device registry, WhatsApp templates.**

- `pushDevices()->opened($publishableKey, $messageId, ?string $token = null)`:
  pass the device's FCM token so the open is verified and the first verified
  report fires `push.opened`; without it the open is recorded, no webhook.
- `send()`: `idempotencyKey` (Idempotency-Key header + `idempotency_key`) and
  `reference`; left out when unset.
- `payload.whatsapp` (approved template options, or `media` with template
  `Custom`) and `payload.tracking` (Email), checked by `CatzConnect\WhatsApp`.
- `$client->messages()->get() / list()`, `$client->otp()->start() / verify()`,
  `$client->manage()->call()` (+ helpers), `$client->pushDevices()->register() /
  unregister() / opened()`.
- `CatzConnect\ErrorCodes`; `ApiException->details`. `send()` refusals are now
  `ApiException` (still an `\Exception` with the message `HTTP <status>: <body>`)
  with `->errorCode` and `->status`. Their `getCode()` stays `0`, as before
  (other API refusals keep the HTTP status as `getCode()`, as in 1.6); read the
  status from `->status`.
- `Webhook::EVENTS`, `Webhook::parseEvent()` (`test` is true for test-key events).
- `CatzConnect::VERSION` is `1.7.0`.


**Cross-channel opt-outs, topics and push broadcasts** (servers with per-channel suppressions):

- `payload['topic']` (Email subscription topic key) and `payload['push']`
  (`broadcast`, `topic`, `send_at` as RFC 3339 or a `DateTimeInterface`); the
  answer's `data` has `broadcast_id`. New `$client->push()` (`PushApi`):
  `broadcast`, `cancel`, `subscribe`, `unsubscribe`, `topicsOf` (server keys only).
- `pushDevices()->subscribeTopics` / `unsubscribeTopics`.
- `messages()->list(['broadcast_id' => …])`.
- `manage()->addSuppression($channel, $address, ['topic' => …, 'detail' => …])`
  for every channel, `removeSuppression(…, ['topic' => …])`,
  `removeSuppressionById`, `listSuppressions`.
- `ErrorCodes::INVALID_TOPIC`, `TOPIC_EMAIL_ONLY`, `RECIPIENT_SUPPRESSED`,
  `WHATSAPP_OPTED_OUT`, `NOT_CANCELABLE`, `NOT_RESENDABLE`, `CONTENT_GONE`;
  `Webhook::EVENTS` adds `email.complained`, `email.unsubscribed`,
  `push.opened`, `sms.inbound`, `sms.opt_out`, `whatsapp.opt_out`.

## 1.6.0 (unreleased, included in 2.0.0)

**Security options.**

- **https only.** A plain `http://` `CATZCONNECT_BASE_URL` to a host other
  than `localhost`, `127.0.0.1`, `::1` or `10.0.2.2` now throws an
  `InvalidArgumentException` before a request is sent (it used to send the
  API key in clear text). Opt in with `CATZCONNECT_ALLOW_INSECURE_HTTP=1` or
  `$catz->setAllowInsecureHttp(true)`. New:
  `CatzConnect\Core\HttpClient::checkBaseUrl()`.
- `CatzConnect::VERSION` is `1.6.0`.

Earlier versions: see the git history.
