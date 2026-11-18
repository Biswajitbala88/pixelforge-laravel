<?php

namespace App\Services\Pixelforge;

use RuntimeException;
use Throwable;

final class PixelforgeApiException extends RuntimeException
{
    /** 429 codes that clear up on their own and are worth retrying. */
    private const TRANSIENT_429 = ['rate_limit_exceeded', 'concurrency_limit_exceeded'];

    public function __construct(
        public readonly int $status,           // 0 = network/connection failure
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function isRetryable(): bool
    {
        if ($this->status === 429) {
            // daily_limit_exceeded won't clear for hours; fail fast. Unknown 429s are treated as transient.
            return in_array($this->errorCode, self::TRANSIENT_429, true) || $this->errorCode === 'unknown';
        }

        // Network failures, 5xx (503 overloaded, 502/504 gateway). Safe to retry because
        // every submission carries the same Idempotency-Key.
        return $this->status === 0 || $this->status >= 500;
    }
}
