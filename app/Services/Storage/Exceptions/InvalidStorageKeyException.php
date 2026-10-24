<?php

namespace App\Services\Storage\Exceptions;

use Throwable;

class InvalidStorageKeyException extends StorageException
{
    public function __construct(string $message = 'The storage object key is invalid or not in an allowed directory.', ?Throwable $previous = null)
    {
        parent::__construct($message, 422, $previous);
    }
}
