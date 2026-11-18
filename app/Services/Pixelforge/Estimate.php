<?php

namespace App\Services\Pixelforge;

final readonly class Estimate
{
    public function __construct(
        public TokenUsage $tokens,
        public int $providerNanos,
        public int $userNanos,
    ) {}

    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens->toArray(),
            'provider_nanos' => $this->providerNanos,
            'user_nanos' => $this->userNanos,
        ];
    }
}
