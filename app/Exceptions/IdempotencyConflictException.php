<?php

namespace App\Exceptions;

/** The idempotency key was already used for a different request. */
class IdempotencyConflictException extends FinancialException
{
    public function __construct(string $message = 'Idempotency key has already been used for a different request.')
    {
        parent::__construct($message);
    }
}
