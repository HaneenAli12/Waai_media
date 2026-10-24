<?php

namespace App\Services\Storage\Exceptions;

use Throwable;

class InvalidImageException extends StorageException
{
    public function __construct(string $message = 'The uploaded file is not a valid supported image.', ?Throwable $previous = null)
    {
        parent::__construct($message, 422, $previous);
    }
}
