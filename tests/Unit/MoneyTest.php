<?php

use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\Estimator;
use App\Services\Pixelforge\Pricing;
use App\Services\Pixelforge\Rates;
use App\Services\Pixelforge\TokenUsage;
use App\Support\Money;

it('converts USD decimal strings to nanodollars exactly', function () {
    expect(Money::usdToNanos('0.046125000'))->toBe(46_125_000)
        ->and(Money::usdToNanos('5.00'))->toBe(5_000_000_000)
        ->and(Money::usdToNanos(12.5))->toBe(12_500_000_000)
        ->and(Money::usdToNanos('0.000000001'))->toBe(1)
        ->and(Money::nanosToUsd(373_875_000))->toBe('0.373875000');
});

it('rejects amounts finer than a nanodollar instead of rounding them', function () {
    Money::usdToNanos('0.0000000001');
})->throws(InvalidArgumentException::class);

it('derives per-token rates from the published per-1M rates', function () {
    // $5.00 / 1M tokens = 5,000 nanos per token, etc.
    $rates = Rates::defaults()->nanosPerMillion;

    expect(intdiv($rates['input_text_tokens'], 1_000_000))->toBe(5_000)
        ->and(intdiv($rates['input_image_tokens'], 1_000_000))->toBe(10_000)
        ->and(intdiv($rates['output_tokens'], 1_000_000))->toBe(40_000)
        ->and(intdiv($rates['reasoning_tokens'], 1_000_000))->toBe(12_500);
});

it('computes provider cost and the 25% markup to the nanodollar ($0.373875000 worked example)', function () {
    // 870 text + 765 image + 6,240 output + 3,000 reasoning tokens at default rates:
    //   870*5,000 + 765*10,000 + 6,240*40,000 + 3,000*12,500
    //   = 4,350,000 + 7,650,000 + 249,600,000 + 37,500,000 = 299,100,000 nanos ($0.299100000)
    //   * 1.25 = 373,875,000 nanos ($0.373875000)
    $calc = new CostCalculator;
    $provider = $calc->providerCost(new TokenUsage(870, 765, 6_240, 3_000), Rates::defaults());

    expect($provider)->toBe(299_100_000)
        ->and(Money::nanosToUsd($provider))->toBe('0.299100000')
        ->and($calc->userCost($provider))->toBe(373_875_000)
        ->and(Money::nanosToUsd($calc->userCost($provider)))->toBe('0.373875000');
});

it('rounds the markup up, never down, on sub-nanodollar fractions', function () {
    expect(Money::withMarkup(1))->toBe(2)          // 1.25 -> 2
        ->and(Money::withMarkup(4))->toBe(5)       // exact
        ->and(Money::withMarkup(3))->toBe(4)       // 3.75 -> 4
        ->and(Money::withMarkup(36_900_000))->toBe(46_125_000)
        ->and(Money::withMarkup(0))->toBe(0);
});

it('estimates text tokens with exact integer ceilings', function (string $prompt, int $expected) {
    expect(Estimator::textTokens($prompt))->toBe($expected);
})->with([
    'empty' => ['', 0],
    '1 char' => ['a', 2],                       // ceil(1/4)=1, ceil(1.15)=2
    '80 chars' => [str_repeat('a', 80), 23],    // 20 * 1.15 = 23 exactly
    '400 chars' => [str_repeat('a', 400), 115], // 100 * 1.15 = 115 (float math gives 114.999…)
    'multibyte counts characters' => [str_repeat('é', 8), 3], // 8 chars, not 16 bytes
]);

it('builds a worst-case estimate from the pricing tables', function () {
    $estimate = (new Estimator(new CostCalculator))->estimate(
        str_repeat('a', 80), ['https://x.test/a.png'], '1024x1536', 'high', Pricing::fallback(),
    );

    // text 23*5,000 + image 765*10,000 + output 6,240*40,000 + reasoning 4,000*12,500
    expect($estimate->tokens->toArray())->toBe([
        'input_text_tokens' => 23, 'input_image_tokens' => 765, 'output_tokens' => 6_240, 'reasoning_tokens' => 4_000,
    ])
        ->and($estimate->providerNanos)->toBe(115_000 + 7_650_000 + 249_600_000 + 50_000_000)
        ->and($estimate->userNanos)->toBe(Money::withMarkup(307_365_000));
});
