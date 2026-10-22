<?php

namespace CatzConnect;

/**
 * The API refused a request: the HTTP status, the server's sentence
 * (getMessage()), and its machine-readable code when it sent one
 * (plan_required, minutes_cap_reached, engine_unavailable,
 * too_many_participants, provider_not_allowed, ...).
 */
class ApiException extends \Exception
{
    public int $status;
    public ?string $errorCode;
    public string $body;
    /**
     * The JSON refusal when the body was JSON, with extra fields such as
     * attempts_remaining (otp_invalid) or retry_after (otp_resend_cooldown).
     */
    public ?array $details = null;

    /**
     * `$exceptionCode` is what getCode() returns; default the HTTP status.
     * send() passes 0, which is what its refusals returned before they
     * became ApiException (2.0.0): read the status from `->status`.
     */
    public function __construct(int $status, string $message, string $body, ?string $errorCode = null, ?int $exceptionCode = null)
    {
        parent::__construct($message, $exceptionCode ?? $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->body = $body;
        $j = json_decode($body, true);
        if (is_array($j)) {
            $this->details = $j;
        }
    }

    public static function fromBody(int $status, string $body): self
    {
        $message = "HTTP $status: $body";
        $code = null;
        $j = json_decode($body, true);
        if (is_array($j)) {
            if (isset($j['message']) && is_string($j['message']) && $j['message'] !== '') {
                $message = $j['message'];
            }
            if (isset($j['code']) && is_string($j['code']) && $j['code'] !== '') {
                $code = $j['code'];
            }
        }
        return new self($status, $message, $body, $code);
    }
}
