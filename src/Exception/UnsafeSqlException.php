<?php

declare(strict_types=1);

namespace App\Exception;

use Exception;

/**
 * Exception thrown when an unsafe SQL query is detected
 */
class UnsafeSqlException extends Exception
{
    public function __construct(
        string $message = 'Unsafe SQL query detected',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
