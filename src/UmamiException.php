<?php

namespace Akibeo\Umami;

use RuntimeException;

class UmamiException extends RuntimeException
{
    /**
     * @param int $status HTTP status of the failed Umami response, 0 when the
     *                    error did not come from a response
     */
    public function __construct(string $message, protected int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }
}
