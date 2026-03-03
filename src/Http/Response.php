<?php

namespace NagaCommerce\SDK\Http;

class Response
{
    private int $statusCode;
    private array $body;

    public function __construct(int $statusCode, array $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Returns the `data` key from the JSON:API response.
     * @return mixed
     */
    public function getData()
    {
        return $this->body['data'] ?? null;
    }

    public function getMeta(): array
    {
        return $this->body['meta'] ?? [];
    }

    public function getErrors(): array
    {
        return $this->body['errors'] ?? [];
    }

    public function getRawBody(): array
    {
        return $this->body;
    }
}
