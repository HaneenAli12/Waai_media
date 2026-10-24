<?php

namespace App\Services\Storage\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class StorageException extends RuntimeException implements HttpExceptionInterface
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message = 'Storage operation failed.',
        private readonly int $statusCode = 500,
        ?Throwable $previous = null,
        private readonly array $headers = []
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
