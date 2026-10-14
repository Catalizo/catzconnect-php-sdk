<?php

namespace CatzConnect;

/**
 * The data of a successful SMS send. The message is queued, not yet
 * delivered; webhook events (sms.sent, sms.delivered, sms.failed,
 * sms.unknown — the provider was called but did not answer, so it is not
 * resent) carry $messageId.
 */
final class SmsResult
{
    public string $messageId;
    /** Billable parts. */
    public int $segments;
    /** "gsm7" or "ucs2". */
    public string $encoding;
    /** The server's message. */
    public string $message;
    /** The whole decoded response. */
    public array $raw;

    public function __construct(string $messageId, int $segments, string $encoding, string $message = '', array $raw = [])
    {
        $this->messageId = $messageId;
        $this->segments = $segments;
        $this->encoding = $encoding;
        $this->message = $message;
        $this->raw = $raw;
    }

    /** Reads `{success, message, data: {message_id, segments, encoding}}`. */
    public static function fromResponse(array $response): self
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || !isset($data['message_id']) || !is_string($data['message_id'])) {
            throw new \Exception('Response has no data.message_id');
        }
        return new self(
            $data['message_id'],
            (int) ($data['segments'] ?? 0),
            (string) ($data['encoding'] ?? ''),
            (string) ($response['message'] ?? ''),
            $response
        );
    }
}
