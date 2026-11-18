<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Snapshot of Pixelforge's published pricing: token rates plus the token tables
 * used for worst-case estimation.
 */
final readonly class Pricing
{
    public const IMAGE_TOKENS_PER_REFERENCE = 765;

    /** Fallback output-token table (quality => size => tokens) if /v1/pricing is unreachable. */
    public const DEFAULT_OUTPUT_TOKENS = [
        'low' => ['1024x1024' => 272, '1536x1024' => 400, '1024x1536' => 408],
        'medium' => ['1024x1024' => 1056, '1536x1024' => 1568, '1024x1536' => 1584],
        'high' => ['1024x1024' => 4160, '1536x1024' => 6208, '1024x1536' => 6240],
    ];

    /** Fallback reasoning ceiling (quality => max tokens). */
    public const DEFAULT_REASONING_MAX = ['low' => 500, 'medium' => 1500, 'high' => 4000];

    /**
     * @param  array<string, array<string,int>>  $outputTokens
     * @param  array<string,int>  $reasoningMax
     */
    public function __construct(
        public Rates $rates,
        public array $outputTokens,
        public array $reasoningMax,
        public string $source, // "live" | "cached" | "fallback"
    ) {}

    public static function fallback(): self
    {
        return new self(Rates::defaults(), self::DEFAULT_OUTPUT_TOKENS, self::DEFAULT_REASONING_MAX, 'fallback');
    }

    /** @param array<string,mixed> $json Raw GET /v1/pricing body. */
    public static function fromApi(array $json, string $source = 'live'): self
    {
        $rates = $json['rates'] ?? $json['token_rates'] ?? $json['prices'] ?? $json;
        $tokens = $json['tokens'] ?? [];

        $output = [];
        foreach (($tokens['output'] ?? []) as $quality => $sizes) {
            foreach ((array) $sizes as $size => $count) {
                $output[$quality][$size] = (int) (is_array($count) ? ($count['tokens'] ?? $count['max'] ?? 0) : $count);
            }
        }

        $reasoning = [];
        foreach (($tokens['reasoning'] ?? []) as $quality => $range) {
            $reasoning[$quality] = (int) (is_array($range) ? ($range['max'] ?? 0) : $range);
        }

        return new self(
            Rates::fromUsdPerMillion(is_array($rates) ? $rates : []),
            $output ?: self::DEFAULT_OUTPUT_TOKENS,
            $reasoning ?: self::DEFAULT_REASONING_MAX,
            $source,
        );
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_values(array_unique(array_merge(...array_map('array_keys', array_values($this->outputTokens)))));
    }

    public function supports(string $size, string $quality): bool
    {
        return isset($this->outputTokens[$quality][$size]);
    }

    /** Shape handed to the frontend estimator. Money as strings so JS can use BigInt. */
    public function toFrontend(): array
    {
        return [
            'source' => $this->source,
            'rates_nanos_per_million' => array_map('strval', $this->rates->nanosPerMillion),
            'output_tokens' => $this->outputTokens,
            'reasoning_max' => $this->reasoningMax,
            'image_tokens_per_reference' => self::IMAGE_TOKENS_PER_REFERENCE,
            'markup_percent' => Money::MARKUP_PERCENT,
            'sizes' => $this->sizes(),
            'qualities' => $this->qualities(),
        ];
    }
}
