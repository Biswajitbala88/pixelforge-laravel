<?php

namespace App\Services\Pixelforge;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /v1/pricing, cached briefly. On failure we fall back to the last known good
 * response, and only then to hard-coded published defaults.
 */
class PricingService
{
    private const CACHE_KEY = 'pixelforge:pricing';

    private const LAST_GOOD_KEY = 'pixelforge:pricing:last-good';

    public function __construct(private PixelforgeClient $client) {}

    public function current(): Pricing
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return Pricing::fromApi($cached, 'live');
        }

        try {
            $json = $this->client->pricing();
            Cache::put(self::CACHE_KEY, $json, config('services.pixelforge.pricing_cache_seconds'));
            Cache::forever(self::LAST_GOOD_KEY, $json);

            return Pricing::fromApi($json, 'live');
        } catch (Throwable $e) {
            Log::warning('Pixelforge pricing fetch failed; using fallback', ['error' => $e->getMessage()]);

            $lastGood = Cache::get(self::LAST_GOOD_KEY);
            $pricing = is_array($lastGood) ? Pricing::fromApi($lastGood, 'cached') : Pricing::fallback();

            // Short negative cache so a Pixelforge outage doesn't add latency to every page load.
            Cache::put(self::CACHE_KEY, is_array($lastGood) ? $lastGood : null, 30);

            return $pricing;
        }
    }
}
