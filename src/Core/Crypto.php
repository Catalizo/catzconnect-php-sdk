<?php

namespace CatzConnect\Core;

class Crypto
{
    public static function encrypt(array $payload): array
    {
        $privateKeyB64 = Env::get('CATZCONNECT_PRIVATE_KEY');
        $serverPubB64 = Env::get('CATZCONNECT_SERVER_PUBLIC_KEY');

        if (!$privateKeyB64 || !$serverPubB64) {
            throw new \Exception("Missing encryption keys in environment");
        }

        $fp = array_merge($payload, [
            'ts' => (int) round(microtime(true) * 1000),
        ]);

        $clientPriv = base64_decode($privateKeyB64);
        $serverPub = base64_decode($serverPubB64);

        $shared = sodium_crypto_scalarmult($clientPriv, $serverPub);

        $master = sodium_crypto_generichash($shared, '', 32);

        $keyEnc = sodium_crypto_generichash($master . 'CONNECT-@-2026-HS-@-CATZ', '', 32);

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
            json_encode($fp),
            '',
            $nonce,
            $keyEnc
        );

        return [
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }
}