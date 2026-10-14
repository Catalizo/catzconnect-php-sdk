<?php

namespace CatzConnect\Core;

class HttpClient
{
    public function post(string $path, array $body): array
    {
        // A trailing slash would produce //sdk/send, which the server does not route.
        $baseURL = rtrim(Env::get('CATZCONNECT_BASE_URL') ?? 'https://api.catzconnect.com', '/');
        $apiKey = Env::get('CATZCONNECT_API_KEY');

        if (!$apiKey) {
            throw new \Exception("Missing API key");
        }

        $ch = curl_init($baseURL . $path);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        if ($status >= 400) {
            throw new \Exception("HTTP $status: $response");
        }

        return json_decode($response, true);
    }
}