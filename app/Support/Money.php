<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Integer nanodollar arithmetic. 1 USD = 1,000,000,000 nanos.
 *
 * Pixelforge reports money to 9 decimal places, so nanodollars represent every
 * provider amount exactly. Floats never touch money: decimal strings are
 * converted with bcmath and everything afterwards is int math.
 */
final class Money
{
    public const NANOS_PER_USD = 1_000_000_000;

    /** Markup applied to provider cost, as a percentage (125 = cost + 25%). */
    public const MARKUP_PERCENT = 125;

    /**
     * Convert a USD decimal ("0.046125000", "5.00", 12.5) to nanodollars.
     * Digits beyond the 9th decimal place are rejected rather than silently rounded.
     */
    public static function usdToNanos(string|int|float $usd): int
    {
        $value = is_float($usd) ? self::floatToDecimalString($usd) : trim((string) $usd);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a decimal USD amount: [{$value}]");
        }

        $nanos = bcmul($value, (string) self::NANOS_PER_USD, 9);
        [$whole, $fraction] = array_pad(explode('.', $nanos), 2, '');

        if (rtrim($fraction, '0') !== '') {
            throw new InvalidArgumentException("USD amount [{$value}] has more precision than 1 nanodollar.");
        }

        return (int) $whole;
    }

    /** Nanodollars to a fixed 9-decimal USD string, e.g. 46125000 -> "0.046125000". */
    public static function nanosToUsd(int $nanos, int $decimals = 9): string
    {
        return bcdiv((string) $nanos, (string) self::NANOS_PER_USD, $decimals);
    }

    /** User price = ceil(provider * 1.25), in exact integer math. Never under-charges. */
    public static function withMarkup(int $providerNanos): int
    {
        return self::ceilDiv($providerNanos * self::MARKUP_PERCENT, 100);
    }

    /** Ceiling division for non-negative integers. */
    public static function ceilDiv(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('ceilDiv expects a non-negative numerator and positive denominator.');
        }

        return intdiv($numerator + $denominator - 1, $denominator);
    }

    private static function floatToDecimalString(float $value): string
    {
        // JSON numbers like 12.5 decode to float; PHP 8's shortest round-trip repr is exact for these.
        $repr = (string) $value;

        return str_contains($repr, 'E') ? sprintf('%.9F', $value) : $repr;
    }
}
