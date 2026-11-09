<?php

namespace App\Services\ProductImages;

use RuntimeException;
use Throwable;

class ImageDownloadException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $downloaded, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
