<?php

namespace App\Services\Storage\Exceptions;

use Throwable;

class ImageUploadException extends StorageException
{
    public function __construct(string $message = 'Failed to upload image to storage.', ?Throwable $previous = null)
    {
        parent::__construct($message, 502, $previous);
    }
}
