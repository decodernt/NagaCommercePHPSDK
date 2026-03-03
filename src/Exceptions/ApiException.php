<?php

namespace NagaCommerce\SDK\Exceptions;

class ApiException extends \RuntimeException
{
    protected int $statusCode;
    protected string $errorTitle;
    protected string $errorDetail;

    public function __construct(int $statusCode, string $title, string $detail = '', ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        $this->errorTitle = $title;
        $this->errorDetail = $detail;

        parent::__construct("[{$statusCode}] {$title}: {$detail}", $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorTitle(): string
    {
        return $this->errorTitle;
    }

    public function getErrorDetail(): string
    {
        return $this->errorDetail;
    }
}
