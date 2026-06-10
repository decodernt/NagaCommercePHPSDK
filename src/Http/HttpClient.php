<?php

namespace NagaCommerce\SDK\Http;

use NagaCommerce\SDK\Exceptions\ApiException;
use NagaCommerce\SDK\Exceptions\AuthenticationException;
use NagaCommerce\SDK\Exceptions\ValidationException;

class HttpClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;
    private bool $verifySsl;
    private int $maxRetries;

    /**
     * @param string $baseUrl  Store API base URL (e.g. "https://store.example.com/api")
     * @param string $apiKey   API key for authentication
     * @param int    $timeout  Request timeout in seconds
     * @param array  $options  Optional flags:
     *   - 'verify_ssl' (bool, default true): when false, disables cURL's
     *     SSL peer + host verification. Only flip OFF for local dev /
     *     integration tests against `.test` domains with self-signed
     *     certs. NEVER disable in production.
     *   - 'max_retries' (int, default 3): how many times to retry on 429
     *     and 503 responses. Honors the `Retry-After` header when the
     *     server sends one; otherwise uses exponential backoff capped
     *     at 30s per attempt. Pass 0 to disable retries.
     */
    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30, array $options = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->verifySsl = array_key_exists('verify_ssl', $options) ? (bool)$options['verify_ssl'] : true;
        $this->maxRetries = array_key_exists('max_retries', $options) ? max(0, (int)$options['max_retries']) : 3;
    }

    public function get(string $path, array $query = []): Response
    {
        $url = $this->buildUrl($path, $query);
        return $this->request('GET', $url);
    }

    public function post(string $path, array $data = []): Response
    {
        $url = $this->buildUrl($path);
        return $this->request('POST', $url, $data);
    }

    public function put(string $path, array $data = []): Response
    {
        $url = $this->buildUrl($path);
        return $this->request('PUT', $url, $data);
    }

    public function delete(string $path, array $query = []): Response
    {
        $url = $this->buildUrl($path, $query);
        return $this->request('DELETE', $url);
    }

    private function buildUrl(string $path, array $query = []): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private function request(string $method, string $url, ?array $data = null): Response
    {
        // Retry transient (429, 503) responses with backoff. Anything else
        // — auth errors, validation, server bugs — falls through on the
        // first attempt.
        $attempt = 0;
        while (true) {
            [$statusCode, $parsed, $retryAfter] = $this->executeOnce($method, $url, $data);

            $isTransient = ($statusCode === 429 || $statusCode === 503);
            if ($isTransient && $attempt < $this->maxRetries) {
                $sleep = $retryAfter > 0
                    ? min(60, $retryAfter)
                    : min(30, 2 ** $attempt); // 1s, 2s, 4s, 8s, ... capped
                sleep($sleep);
                $attempt++;
                continue;
            }

            if ($statusCode >= 400) {
                $this->throwForStatus($statusCode, $parsed);
            }
            return new Response($statusCode, $parsed);
        }
    }

    /**
     * Single cURL execution. Returns [statusCode, parsedBody, retryAfterSeconds].
     * Throws only on cURL transport failure or non-JSON response bodies.
     */
    private function executeOnce(string $method, string $url, ?array $data): array
    {
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,  // include headers in response body so we can read Retry-After
        ];
        if (!$this->verifySsl) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            // PUT / DELETE / PATCH all go through CUSTOMREQUEST. Setting POST=true
            // would force the body type to multipart/form-data on PUT, which
            // breaks JSON parsing on the server.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($data !== null) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new ApiException(0, 'Connection Error', $curlError);
        }

        $headerBlob = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);

        // Pull Retry-After (seconds or HTTP-date). We only honor numeric
        // seconds — HTTP-date is rarely used by API rate-limiters and
        // would need timezone math to interpret.
        $retryAfter = 0;
        if (preg_match('/^Retry-After:\s*(\d+)/im', $headerBlob, $m)) {
            $retryAfter = (int)$m[1];
        }

        $parsed = json_decode($responseBody, true);
        if (!is_array($parsed)) {
            throw new ApiException($statusCode, 'Invalid Response', 'The API returned non-JSON content.');
        }

        return [$statusCode, $parsed, $retryAfter];
    }

    private function throwForStatus(int $statusCode, array $body): void
    {
        $title = 'API Error';
        $detail = '';

        if (!empty($body['errors'][0])) {
            $error = $body['errors'][0];
            $title = $error['title'] ?? $title;
            $detail = $error['detail'] ?? '';
        }

        if ($statusCode === 401 || $statusCode === 403) {
            throw new AuthenticationException($statusCode, $title, $detail);
        }

        if ($statusCode === 422) {
            throw new ValidationException($statusCode, $title, $detail);
        }

        throw new ApiException($statusCode, $title, $detail);
    }
}
