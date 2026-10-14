<?php

namespace CatzConnect;

/**
 * Server-side push helpers.
 *
 *     $hash = \CatzConnect\Push::computeUserHash($user->id, getenv('CATZCONNECT_PUSH_IDENTITY_SECRET'));
 *     // give $user->id and $hash to your app; it sends both to POST /push/register
 */
class Push
{
    /**
     * The user_hash for POST /push/register: hex HMAC-SHA256 of the
     * external_user_id, keyed with the push project's identity secret
     * (pis_…, Push → Projects in the panel).
     *
     * Compute it on your server. The identity secret must never ship inside
     * the app — anyone holding it could register their device as any of your
     * users. Without a valid hash the device is registered with no user.
     */
    public static function computeUserHash(string $externalUserId, string $identitySecret): string
    {
        if ($identitySecret === '') {
            throw new \InvalidArgumentException('computeUserHash: identity secret is required');
        }
        return hash_hmac('sha256', $externalUserId, $identitySecret);
    }
}
