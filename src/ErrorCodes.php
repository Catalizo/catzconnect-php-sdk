<?php

namespace CatzConnect;

/**
 * Machine codes the API sends in `code` (ApiException->errorCode). Branch on
 * these, not on the (translated) message.
 */
final class ErrorCodes
{
    public const ORGANIZATION_SUSPENDED = 'organization_suspended';
    public const SENDING_PAUSED = 'sending_paused';
    public const FEATURE_NOT_IN_PLAN = 'feature_not_in_plan';
    public const PLAN_REQUIRED = 'plan_required';
    public const CHANNEL_LIMIT_REACHED = 'channel_limit_reached';
    public const DAILY_LIMIT_REACHED = 'daily_limit_reached';
    public const MONTHLY_LIMIT_REACHED = 'monthly_limit_reached';
    public const MINUTES_CAP_REACHED = 'minutes_cap_reached';
    public const KEY_EXPIRED = 'key_expired';
    public const IP_NOT_ALLOWED = 'ip_not_allowed';
    public const KEY_RATE_LIMITED = 'key_rate_limited';
    public const KEY_SEND_LIMIT = 'key_send_limit';
    public const NOT_ALLOWED_FOR_APP_KEYS = 'not_allowed_for_app_keys';
    public const NOT_ALLOWED_FOR_TEST_KEYS = 'not_allowed_for_test_keys';
    public const MANAGEMENT_SCOPE_REQUIRED = 'management_scope_required';
    public const WHATSAPP_INVALID_TEMPLATE = 'whatsapp_invalid_template';
    public const WHATSAPP_TEMPLATE_NOT_APPROVED = 'whatsapp_template_not_approved';
    public const WHATSAPP_TEMPLATE_LANGUAGE_REQUIRED = 'whatsapp_template_language_required';
    public const WHATSAPP_INVALID_TEMPLATE_PARAMS = 'whatsapp_invalid_template_params';
    public const WHATSAPP_INVALID_MEDIA = 'whatsapp_invalid_media';
    public const WHATSAPP_WINDOW_CLOSED = 'whatsapp_window_closed';
    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    public const IDEMPOTENCY_IN_PROGRESS = 'idempotency_in_progress';
    public const INVALID_IDEMPOTENCY_KEY = 'invalid_idempotency_key';
    public const INVALID_REFERENCE = 'invalid_reference';
    public const MESSAGE_NOT_FOUND = 'message_not_found';
    public const OTP_INVALID = 'otp_invalid';
    public const OTP_EXPIRED = 'otp_expired';
    public const OTP_NOT_FOUND = 'otp_not_found';
    public const OTP_ALREADY_VERIFIED = 'otp_already_verified';
    public const OTP_ATTEMPTS_EXCEEDED = 'otp_attempts_exceeded';
    public const OTP_RESEND_COOLDOWN = 'otp_resend_cooldown';
    public const OTP_SEND_LIMIT = 'otp_send_limit';
    public const UNSUPPORTED_CHANNEL = 'unsupported_channel';
    public const UNKNOWN_OPERATION = 'unknown_operation';
    public const INVALID_REQUEST = 'invalid_request';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    // Opt-outs, topics, push broadcasts, resends
    public const INVALID_TOPIC = 'invalid_topic';
    public const TOPIC_EMAIL_ONLY = 'topic_email_only';
    public const RECIPIENT_SUPPRESSED = 'recipient_suppressed';
    public const WHATSAPP_OPTED_OUT = 'whatsapp_opted_out';
    public const NOT_CANCELABLE = 'not_cancelable';
    public const NOT_RESENDABLE = 'not_resendable';
    public const CONTENT_GONE = 'content_gone';

    /** Refusals worth retrying later with the same request. */
    public static function isRetryable(?string $code): bool
    {
        return in_array($code, [self::SENDING_PAUSED, self::KEY_RATE_LIMITED, self::KEY_SEND_LIMIT, self::IDEMPOTENCY_IN_PROGRESS, self::OTP_RESEND_COOLDOWN], true);
    }
}
