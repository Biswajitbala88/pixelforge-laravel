<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POSTs a generation to Pixelforge.
 *
 * Two kinds of waiting:
 *  - Local back-pressure (our own in-flight cap / submit lock busy): release and
 *    try again shortly. Doesn't count against the provider-retry budget.
 *  - Provider back-pressure (429 rate/concurrency, 503, 5xx, network): release for
 *    max(Retry-After, 2^attempt) seconds, at most `max_submit_attempts` times.
 *
 * Retries are safe: every POST for a generation carries the same Idempotency-Key.
 */
class SubmitGenerationJob implements ShouldQueue
{
    use Queueable;

    /** Release() increments attempts, so bound the job by time rather than count. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function __construct(public string $generationId) {}

    public function handle(PixelforgeClient $client, GenerationStateMachine $machine): void
    {
        $generation = Generation::find($this->generationId);

        // Gone, or already moved past submitting (e.g. a webhook beat us here on a retry).
        if (! $generation || $generation->status !== GenerationStatus::Submitting) {
            return;
        }

        $lock = Cache::lock('pixelforge:submit-slot', 30);

        if (! $lock->get()) {
            $this->release(2);

            return;
        }

        try {
            if ($this->inFlight() >= config('services.pixelforge.max_in_flight')) {
                $this->release(3);

                return;
            }

            $remote = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images ?? [],
                'metadata' => ['local_generation_id' => $generation->id],
            ], $generation->idempotency_key);

            // Usually status=queued. If a webhook already advanced us further, this is a no-op.
            $machine->apply($remote + ['metadata' => ['local_generation_id' => $generation->id]], GenerationStatus::Queued);
        } catch (PixelforgeApiException $e) {
            $this->handleApiError($generation, $e, $machine);
        } finally {
            $lock->release();
        }
    }

    private function handleApiError(Generation $generation, PixelforgeApiException $e, GenerationStateMachine $machine): void
    {
        if (! $e->isRetryable()) {
            $machine->failSubmission($generation->id, $e->errorCode, $e->getMessage());

            return;
        }

        $attempt = $generation->submit_attempts + 1;
        $generation->forceFill(['submit_attempts' => $attempt])->saveQuietly();

        if ($attempt >= config('services.pixelforge.max_submit_attempts')) {
            $machine->failSubmission($generation->id, $e->errorCode,
                "Pixelforge was unavailable after {$attempt} attempts ({$e->getMessage()}).");

            return;
        }

        $delay = min(120, max($e->retryAfter ?? 5, 2 ** $attempt)) + random_int(0, 1);

        Log::info('Pixelforge submit deferred', [
            'generation_id' => $generation->id, 'status' => $e->status, 'code' => $e->errorCode,
            'attempt' => $attempt, 'delay' => $delay,
        ]);

        $this->release($delay);
    }

    private function inFlight(): int
    {
        // Ignore very old rows so a missed webhook can't wedge the queue; the reconciler cleans those up.
        return Generation::whereIn('status', [GenerationStatus::Queued->value, GenerationStatus::Processing->value])
            ->where('updated_at', '>', now()->subMinutes(10))
            ->count();
    }

    public function failed(?Throwable $e): void
    {
        app(GenerationStateMachine::class)->failSubmission(
            $this->generationId,
            'submission_failed',
            'We could not submit this generation to Pixelforge. You were not charged.',
        );
    }
}
