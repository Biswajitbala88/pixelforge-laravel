<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Token rates, held as integer nanodollars per 1,000,000 tokens.
 * ($5.00 / 1M tokens = 5,000,000,000 nanos / 1M = 5,000 nanos per token.)
 */
final readonly class Rates
{
    public const KEYS = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    public const DEFAULT_USD_PER_MILLION = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** @param array<string,int> $nanosPerMillion */
    public function __construct(public array $nanosPerMillion) {}

    public static function defaults(): self
    {
        return self::fromUsdPerMillion(self::DEFAULT_USD_PER_MILLION);
    }

    /**
     * Build from a {key: usd_per_1M} map. Values may be decimal strings, numbers, or
     * objects carrying the rate under a common key. Missing keys fall back to defaults.
     *
     * @param  array<string,mixed>  $usdPerMillion
     */
    public static function fromUsdPerMillion(array $usdPerMillion): self
    {
        $nanos = [];

        foreach (self::KEYS as $key) {
            $raw = $usdPerMillion[$key] ?? self::DEFAULT_USD_PER_MILLION[$key];

            if (is_array($raw)) {
                $raw = $raw['usd_per_1m_tokens'] ?? $raw['usd_per_million_tokens'] ?? $raw['usd'] ?? $raw['price'] ?? $raw['rate']
                    ?? self::DEFAULT_USD_PER_MILLION[$key];
            }

            $nanos[$key] = Money::usdToNanos($raw);
        }

        return new self($nanos);
    }

    /** @return array<string,string> {key: "5.000000000"} — the snapshot format persisted on generations. */
    public function toUsdPerMillion(): array
    {
        return array_map(fn (int $n) => Money::nanosToUsd($n), $this->nanosPerMillion);
    }
}
