<?php

declare(strict_types=1);

namespace SfuKit;

/** A non-2xx answer from the kit (or a bad argument caught before calling it, status 400). */
class SfuKitError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly mixed $body = null)
    {
        parent::__construct($message, $status);
    }
}
