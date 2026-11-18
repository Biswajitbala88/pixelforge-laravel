<?php

namespace App\Services\Pixelforge;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Worst-case upfront estimate. Mirrored exactly by resources/js/lib/pricing.ts —
 * keep the two in sync (a test pins the shared fixture).
 */
final class Estimator
{
    public function __construct(private CostCalculator $calculator) {}

    /** @param list<string> $referenceImages */
    public function estimate(string $prompt, array $referenceImages, string $size, string $quality, Pricing $pricing): Estimate
    {
        if (! $pricing->supports($size, $quality)) {
            throw new InvalidArgumentException("Unsupported size/quality combination: {$size}/{$quality}");
        }

        $tokens = new TokenUsage(
            inputTextTokens: self::textTokens($prompt),
            inputImageTokens: count($referenceImages) * Pricing::IMAGE_TOKENS_PER_REFERENCE,
            outputTokens: $pricing->outputTokens[$quality][$size],
            reasoningTokens: $pricing->reasoningMax[$quality] ?? 0,
        );

        $provider = $this->calculator->providerCost($tokens, $pricing->rates);

        return new Estimate($tokens, $provider, Money::withMarkup($provider));
    }

    /**
     * ceil(ceil(chars / 4) * 1.15), done in integers: ceil(x * 115 / 100).
     * (The float form can be off by one when x * 1.15 lands on a binary rounding edge.)
     */
    public static function textTokens(string $prompt): int
    {
        $base = Money::ceilDiv(mb_strlen($prompt, 'UTF-8'), 4);

        return Money::ceilDiv($base * 115, 100);
    }
}
