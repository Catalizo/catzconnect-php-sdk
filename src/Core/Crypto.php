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

    /** X25519 → BLAKE2b-256 → BLAKE2b-256(master ‖ label): the key the server derives. */
    private static function key(): string
    {
        $privateKeyB64 = Env::get('CATZCONNECT_PRIVATE_KEY');
        $serverPubB64 = Env::get('CATZCONNECT_SERVER_PUBLIC_KEY');
        if (!$privateKeyB64 || !$serverPubB64) {
            throw new \Exception("Missing encryption keys in environment");
        }
        $shared = sodium_crypto_scalarmult(base64_decode($privateKeyB64), base64_decode($serverPubB64));
        $master = sodium_crypto_generichash($shared, '', 32);
        return sodium_crypto_generichash($master . 'CONNECT-@-2026-HS-@-CATZ', '', 32);
    }

    private static function isSealed($v): bool
    {
        return is_array($v) && count($v) === 2
            && isset($v['nonce'], $v['ciphertext']) && is_string($v['nonce']) && is_string($v['ciphertext']);
    }

    /**
     * Opens an encrypted {nonce, ciphertext} envelope (at the top level or as
     * data) with the request key and merges it in. Plain JSON — what /sdk/*
     * returns today — is passed through.
     */
    public static function openResponse(array $res): array
    {
        $top = self::isSealed($res);
        if (!$top && !(isset($res['data']) && self::isSealed($res['data']))) {
            return $res;
        }
        $env = $top ? $res : $res['data'];
        $plain = sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
            base64_decode($env['ciphertext']),
            '',
            base64_decode($env['nonce']),
            self::key()
        );
        if ($plain === false) {
            throw new \Exception('The response is encrypted with a key this SDK cannot open');
        }
        $opened = json_decode($plain, true);
        if ($top) {
            return is_array($opened) ? $opened : ['data' => $opened];
        }
        $out = $res;
        if (is_array($opened)) {
            $out = array_merge($out, $opened);
        }
        $out['data'] = $opened;
        return $out;
    }
}
