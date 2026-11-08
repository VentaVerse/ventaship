<?php

namespace App\Platforms;

use RuntimeException;
use Throwable;

/** A refusal from PlatformApps, with a short message fit to show the admin and, when known, the form field it concerns. */
class PlatformAppException extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?string $field = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
