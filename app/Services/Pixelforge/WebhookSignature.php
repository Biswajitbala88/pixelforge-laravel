<?php

namespace App\Services\Pixelforge;

/**
 * Verifies `Pixelforge-Signature: t=<unix>,v1=<hex hmac-sha256>`.
 * Signed payload is "{t}.{raw_body}". Multiple v1 values are accepted (secret rotation).
 */
final class WebhookSignature
{
    public function __construct(private string $secret, private int $toleranceSeconds = 300) {}

    public function verify(string $rawBody, ?string $header, ?int $now = null): bool
    {
        if ($this->secret === '' || ! $header) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = self::sign($this->secret, $timestamp, $rawBody);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    public static function header(string $secret, int $timestamp, string $rawBody): string
    {
        return "t={$timestamp},v1=".self::sign($secret, $timestamp, $rawBody);
    }
}
