<?php

namespace CatzConnect\Core;

class HttpClient
{
    /** Hosts that never leave the machine (10.0.2.2 is the Android emulator's host). */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '10.0.2.2'];

    /** Accept a plain-http base URL to a non-local host. */
    private bool $allowInsecureHttp = false;

    public function setAllowInsecureHttp(bool $allow): void
    {
        $this->allowInsecureHttp = $allow;
    }

    /**
     * Accepts https:// always, and plain http:// only for localhost,
     * 127.0.0.1, ::1 and 10.0.2.2 or when $allowInsecure is set, so the API
     * key is never sent unencrypted by mistake.
     *
     * @throws \InvalidArgumentException
     */
    public static function checkBaseUrl(string $url, bool $allowInsecure = false): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if ($host === '') {
            throw new \InvalidArgumentException("CATZCONNECT_BASE_URL is not a valid URL: \"$url\"");
        }
        if ($scheme === 'https') {
            return;
        }
        if ($scheme !== 'http') {
            throw new \InvalidArgumentException("CATZCONNECT_BASE_URL must start with https:// (got \"$scheme:\")");
        }
        if ($allowInsecure || in_array($host, self::LOCAL_HOSTS, true)) {
            return;
        }
        throw new \InvalidArgumentException(
            "CATZCONNECT_BASE_URL uses plain http:// to $host: the API key would travel unencrypted. "
            . 'Use https://, or set CATZCONNECT_ALLOW_INSECURE_HTTP=1 / $catz->setAllowInsecureHttp(true) to allow it anyway.'
        );
    }

    /** CATZCONNECT_BASE_URL (or the default), without a trailing slash, checked. */
    protected function baseUrl(): string
    {
        // A trailing slash would produce //sdk/send, which the server does not route.
        $baseURL = rtrim(Env::get('CATZCONNECT_BASE_URL') ?? 'https://api.catzconnect.com', '/');
        $env = strtolower(Env::get('CATZCONNECT_ALLOW_INSECURE_HTTP') ?? '');
        self::checkBaseUrl($baseURL, $this->allowInsecureHttp || in_array($env, ['1', 'true', 'yes'], true));
        return $baseURL;
    }

    /**
     * POSTs an encrypted body and returns the decoded JSON. Non-2xx answers,
     * and answers with success: false, throw \CatzConnect\ApiException.
     */
    public function request(string $path, array $body): array
    {
        $baseURL = $this->baseUrl();
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

    /**
     * send(): POSTs an encrypted body. A refusal throws ApiException (a
     * \Exception) whose message is still "HTTP <status>: <body>", with
     * ->status, ->errorCode and ->details.
     */
    public function post(string $path, array $body, array $extraHeaders = []): array
    {
        $baseURL = $this->baseUrl();
        $apiKey = Env::get('CATZCONNECT_API_KEY');

        if (!$apiKey) {
            throw new \Exception("Missing API key");
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        [$status, $response] = $this->transport($baseURL . $path, $headers, json_encode($body));

        if ($status >= 400) {
            $code = \CatzConnect\ApiException::fromBody($status, $response)->errorCode;
            // getCode() stays 0, as for the plain \Exception thrown here
            // before 2.0.0; the status is on ->status.
            throw new \CatzConnect\ApiException($status, "HTTP $status: $response", $response, $code, 0);
        }

        return json_decode($response, true);
    }

    /**
     * POSTs plain JSON without an API key (the public push device
     * endpoints, authorised by the project's publishable key).
     */
    public function postPublic(string $path, array $body, ?string $baseUrl = null): array
    {
        if ($baseUrl === null) {
            $baseURL = $this->baseUrl();
        } else {
            $baseURL = rtrim(trim($baseUrl), '/');
            $env = strtolower(Env::get('CATZCONNECT_ALLOW_INSECURE_HTTP') ?? '');
            self::checkBaseUrl($baseURL, $this->allowInsecureHttp || in_array($env, ['1', 'true', 'yes'], true));
        }
        [$status, $response] = $this->transport($baseURL . $path, [
            'Content-Type: application/json',
            'Accept: application/json',
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
}