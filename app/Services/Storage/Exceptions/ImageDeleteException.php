<?php

namespace App\Services\Storage\Exceptions;

use Throwable;

class ImageDeleteException extends StorageException
{
    public function __construct(string $message = 'Failed to delete image from storage.', ?Throwable $previous = null)
    {
        parent::__construct($message, 500, $previous);
    }
}
