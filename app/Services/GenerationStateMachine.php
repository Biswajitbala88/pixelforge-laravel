<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\Rates;
use App\Services\Pixelforge\TokenUsage;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single place generation state changes. Webhooks, the submit job and the
 * reconcile poller all funnel through here.
 *
 * Safety comes from three layers:
 *  1. Row lock (SELECT ... FOR UPDATE) serialises concurrent updates to one generation.
 *  2. Monotonic status_rank: an update applies only if it moves strictly forward,
 *     so late/duplicate events are no-ops and terminal states are never left.
 *  3. Ledger unique (generation_id, type): even if 1 and 2 were bypassed, a second
 *     charge/release cannot be written.
 */
class GenerationStateMachine
{
    public function __construct(private Ledger $ledger, private CostCalculator $calculator) {}

    /**
     * Apply a Pixelforge generation object (from a webhook, POST response or poll).
     *
     * @param  array<string,mixed>  $remote
     * @return Generation|null null if the object does not belong to us.
     */
    public function apply(array $remote, ?GenerationStatus $statusHint = null): ?Generation
    {
        return DB::transaction(function () use ($remote, $statusHint) {
            $generation = $this->lockGeneration($remote);

            if (! $generation) {
                return null;
            }

            if ($generation->pixelforge_id === null && ! empty($remote['id'])) {
                $generation->pixelforge_id = $remote['id'];
            }

            $generation->pixelforge_created_at ??= self::time($remote['created_at'] ?? null);

            $next = GenerationStatus::tryFrom((string) ($remote['status'] ?? '')) ?? $statusHint;

            if ($next !== null && $next->rank() > $generation->status_rank) {
                match ($next) {
                    GenerationStatus::Succeeded => $this->succeed($generation, $remote),
                    GenerationStatus::Failed => $this->fail(
                        $generation,
                        self::errorCode($remote),
                        self::errorMessage($remote),
                        self::time($remote['completed_at'] ?? $remote['failed_at'] ?? null),
                    ),
                    GenerationStatus::Processing => $this->advance($generation, $next, $remote),
                    GenerationStatus::Queued => $this->advance($generation, $next, $remote),
                    GenerationStatus::Submitting => null,
                };
            }

            $generation->save();

            return $generation;
        });
    }

    /** Fail a generation we could not submit (4xx, retries exhausted, daily limit). */
    public function failSubmission(string $generationId, string $code, string $message): void
    {
        DB::transaction(function () use ($generationId, $code, $message) {
            $generation = Generation::whereKey($generationId)->lockForUpdate()->first();

            if (! $generation || $generation->isTerminal()) {
                return;
            }

            $this->fail($generation, $code, $message, null);
            $generation->save();
        });
    }

    private function advance(Generation $generation, GenerationStatus $status, array $remote): void
    {
        $generation->setStatus($status);

        if ($status === GenerationStatus::Processing) {
            $generation->started_at ??= self::time($remote['started_at'] ?? null) ?? now();
        }
    }

    private function succeed(Generation $generation, array $remote): void
    {
        $usage = TokenUsage::fromArray((array) ($remote['usage'] ?? []));
        $rates = isset($remote['rates']) && is_array($remote['rates'])
            ? Rates::fromUsdPerMillion($remote['rates'])
            : Rates::fromUsdPerMillion($generation->rates_snapshot);

        $provider = $this->calculator->providerCost($usage, $rates);
        $user = $this->calculator->userCost($provider);

        $reported = self::reportedCost($remote);
        if ($reported !== null && $reported !== $provider) {
            Log::warning('Pixelforge reported cost differs from computed cost', [
                'generation_id' => $generation->id,
                'computed_nanos' => $provider,
                'reported_nanos' => $reported,
            ]);
        }

        $generation->setStatus(GenerationStatus::Succeeded);
        $generation->fill([
            'usage_snapshot' => $usage->toArray(),
            'actual_provider_cost_nanos' => $provider,
            'actual_user_cost_nanos' => $user,
            'reported_provider_cost_nanos' => $reported,
            'image_url' => $remote['image_url'] ?? $remote['output']['image_url'] ?? $remote['output']['url'] ?? null,
            'image_url_expires_at' => self::time($remote['image_url_expires_at'] ?? $remote['output']['expires_at'] ?? null),
            'completed_at' => self::time($remote['completed_at'] ?? null) ?? now(),
            'error_code' => null,
            'error_message' => null,
        ]);
        $generation->started_at ??= self::time($remote['started_at'] ?? null);

        $this->ledger->releaseHold($generation, 'Release hold (settled to actual cost)');
        $this->ledger->charge($generation, $user);
    }

    private function fail(Generation $generation, string $code, string $message, ?Carbon $completedAt): void
    {
        $generation->setStatus(GenerationStatus::Failed);
        $generation->fill([
            'error_code' => $code,
            'error_message' => $message,
            'actual_provider_cost_nanos' => 0,
            'actual_user_cost_nanos' => 0,
            'completed_at' => $completedAt ?? now(),
        ]);

        $this->ledger->releaseHold($generation, 'Release hold (generation failed — no charge)');
    }

    private function lockGeneration(array $remote): ?Generation
    {
        if (! empty($remote['id'])) {
            $generation = Generation::where('pixelforge_id', $remote['id'])->lockForUpdate()->first();
            if ($generation) {
                return $generation;
            }
        }

        $localId = $remote['metadata']['local_generation_id'] ?? null;

        return is_string($localId) && $localId !== ''
            ? Generation::whereKey($localId)->lockForUpdate()->first()
            : null;
    }

    private static function errorCode(array $remote): string
    {
        $error = $remote['error'] ?? null;

        return (string) (is_array($error) ? ($error['code'] ?? 'generation_failed') : ($remote['failure_code'] ?? 'generation_failed'));
    }

    private static function errorMessage(array $remote): string
    {
        $error = $remote['error'] ?? null;

        return (string) (is_array($error) ? ($error['message'] ?? 'Generation failed.') : ($error ?? $remote['failure_reason'] ?? 'Generation failed.'));
    }

    private static function reportedCost(array $remote): ?int
    {
        $raw = $remote['cost_usd'] ?? $remote['cost']['usd'] ?? $remote['usage']['cost_usd'] ?? null;

        try {
            return $raw === null ? null : Money::usdToNanos($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private static function time(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return is_numeric($value) ? Carbon::createFromTimestampUTC((int) $value) : Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
