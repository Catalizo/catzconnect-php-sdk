<?php

namespace CatzConnect\Core;

class HttpClient
{
    /**
     * POSTs an encrypted body and returns the decoded JSON. Non-2xx answers,
     * and answers with success: false, throw \CatzConnect\ApiException.
     */
    public function request(string $path, array $body): array
    {
        $baseURL = rtrim(Env::get('CATZCONNECT_BASE_URL') ?? 'https://api.catzconnect.com', '/');
        $apiKey = Env::get('CATZCONNECT_API_KEY');
        if (!$apiKey) {
            throw new \Exception("Missing API key");
        }

        [$status, $response] = $this->transport($baseURL . $path, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
            'User-Agent: catzconnect-php-sdk/' . \CatzConnect\CatzConnect::VERSION,
        ], json_encode($body));

        if ($status < 200 || $status >= 300) {
            throw \CatzConnect\ApiException::fromBody($status, $response);
        }
        $json = json_decode($response, true);
        if (!is_array($json)) {
            throw new \CatzConnect\ApiException($status, 'The server sent a response that is not JSON', $response);
        }
        if (($json['success'] ?? null) === false) {
            throw \CatzConnect\ApiException::fromBody($status, $response);
        }
        return $json;
    }

    /**
     * One HTTP POST: returns [status, body]. Override in tests.
     *
     * @return array{0: int, 1: string}
     */
    protected function transport(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception($err);
        }
        curl_close($ch);
        return [$status, (string) $response];
    }

    public function post(string $path, array $body): array
    {
        // A trailing slash would produce //sdk/send, which the server does not route.
        $baseURL = rtrim(Env::get('CATZCONNECT_BASE_URL') ?? 'https://api.catzconnect.com', '/');
        $apiKey = Env::get('CATZCONNECT_API_KEY');

        if (!$apiKey) {
            throw new \Exception("Missing API key");
        }

        [$status, $response] = $this->transport($baseURL . $path, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ], json_encode($body));

        if ($status >= 400) {
            throw new \Exception("HTTP $status: $response");
        }

        return json_decode($response, true);
    }
}