<?php

namespace App\Services\Storage\Exceptions;

use Throwable;

class StorageConfigurationException extends StorageException
{
    public function __construct(string $message = 'Storage public delivery configuration is missing or invalid.', ?Throwable $previous = null)
    {
        parent::__construct($message, 500, $previous);
    }
}
