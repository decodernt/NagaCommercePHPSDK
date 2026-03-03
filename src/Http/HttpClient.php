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

    public function __construct(string $baseUrl, string $apiKey, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
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
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        }

        if ($data !== null) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $responseBody = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new ApiException(0, 'Connection Error', $curlError);
        }

        $parsed = json_decode($responseBody, true);
        if (!is_array($parsed)) {
            throw new ApiException($statusCode, 'Invalid Response', 'The API returned non-JSON content.');
        }

        if ($statusCode >= 400) {
            $this->throwForStatus($statusCode, $parsed);
        }

        return new Response($statusCode, $parsed);
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
