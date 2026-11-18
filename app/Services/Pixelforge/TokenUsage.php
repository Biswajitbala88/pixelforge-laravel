<?php

namespace App\Services\Pixelforge;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens = 0,
        public int $inputImageTokens = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
    ) {}

    /** @param array<string,mixed> $usage */
    public static function fromArray(array $usage): self
    {
        return new self(
            (int) ($usage['input_text_tokens'] ?? 0),
            (int) ($usage['input_image_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['reasoning_tokens'] ?? 0),
        );
    }

    /** @return array<string,int> */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
