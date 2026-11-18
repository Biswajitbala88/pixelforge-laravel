<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Estimator;
use App\Services\Pixelforge\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerationService
{
    public function __construct(
        private PricingService $pricing,
        private Estimator $estimator,
        private Ledger $ledger,
    ) {}

    /**
     * Price the request server-side (never trust the browser's number), hold the
     * estimate against the user's balance, persist, then queue the submission.
     *
     * @param  array{prompt:string,size:string,quality:string,reference_images?:list<string>}  $input
     */
    public function create(User $user, array $input): Generation
    {
        $pricing = $this->pricing->current();
        $references = array_values(array_filter($input['reference_images'] ?? []));

        if (! $pricing->supports($input['size'], $input['quality'])) {
            throw ValidationException::withMessages(['size' => 'That size/quality combination is not available.']);
        }

        $estimate = $this->estimator->estimate($input['prompt'], $references, $input['size'], $input['quality'], $pricing);

        $generation = DB::transaction(function () use ($user, $input, $references, $pricing, $estimate) {
            // Lock the user row so two concurrent submits can't both pass the balance check.
            $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('credit_balance_nanos');

            if ($balance < $estimate->userNanos) {
                throw ValidationException::withMessages([
                    'balance' => 'Not enough credit to cover the estimated cost. Please buy credits.',
                ]);
            }

            $generation = new Generation([
                'user_id' => $user->id,
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $references ?: null,
                'rates_snapshot' => $pricing->rates->toUsdPerMillion(),
                'estimate_snapshot' => $estimate->toArray() + ['pricing_source' => $pricing->source],
                'estimated_provider_cost_nanos' => $estimate->providerNanos,
                'estimated_user_cost_nanos' => $estimate->userNanos,
            ]);
            $generation->setStatus(GenerationStatus::Submitting);
            $generation->save();

            $this->ledger->hold($generation);

            return $generation;
        });

        SubmitGenerationJob::dispatch($generation->id);

        return $generation;
    }
}
