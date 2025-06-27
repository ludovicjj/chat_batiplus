<?php

namespace App\Exception;

use Exception;
use Throwable;

class UnsafeElasticsearchQueryException extends Exception
{
    public function __construct(
        string     $message = 'Unsafe QueryBody detected',
        int        $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}