<?php

namespace App\Exception;

use Exception;
use Throwable;

class ValidatorException extends Exception
{
    private array $errors;

    public function __construct(
        string      $message,
        array       $errors = [],
        int         $code = 0,
        ?Throwable  $previous = null
    ) {
        $this->errors = $errors;
        parent::__construct($message, $code, $previous);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}