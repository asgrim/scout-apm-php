<?php

declare(strict_types=1);

namespace Scoutapm\Exception\Timer;

use Exception;
use Throwable;

class NotStopped extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct('Can\'t get the duration of a running timer.', $code, $previous);
    }
}
