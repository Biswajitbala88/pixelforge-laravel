<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

final class CostCalculator
{
    /**
     * Exact provider cost in nanodollars:
     *   sum(tokens_k * nanos_per_1M_k) / 1,000,000
     *
     * Products are summed first and divided once, so no per-line rounding error
     * accumulates. With Pixelforge's published rates the division is always exact;
     * if a future rate ever produced a sub-nanodollar remainder we round *up*.
     */
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        $r = $rates->nanosPerMillion;

        $scaled = $usage->inputTextTokens * $r['input_text_tokens']
            + $usage->inputImageTokens * $r['input_image_tokens']
            + $usage->outputTokens * $r['output_tokens']
            + $usage->reasoningTokens * $r['reasoning_tokens'];

        return Money::ceilDiv($scaled, 1_000_000);
    }

    public function userCost(int $providerNanos): int
    {
        return Money::withMarkup($providerNanos);
    }
}
