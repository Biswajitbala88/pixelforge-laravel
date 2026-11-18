<?php

use App\Enums\GenerationStatus;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use App\Services\GenerationStateMachine;
use App\Services\Pixelforge\CostCalculator;
use App\Services\Pixelforge\Rates;
use App\Services\Pixelforge\TokenUsage;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.pixelforge.webhook_secret' => TEST_WEBHOOK_SECRET]));

it('places a hold for the estimate when a generation is created', function () {
    $g = makeGeneration();
    $user = $g->user->fresh();

    expect($g->status)->toBe(GenerationStatus::Submitting)
        ->and($user->credit_balance_nanos)->toBe(5_000_000_000 - $g->estimated_user_cost_nanos)
        ->and(ledgerSum($user->id))->toBe($user->credit_balance_nanos);
});

it('settles a success: releases the hold and charges the exact actual cost', function () {
    $g = makeGeneration();
    postWebhook($this, webhookEvent('evt_s', 'generation.succeeded', succeededObject($g)))->assertNoContent();

    $g->refresh();
    $expectedProvider = (new CostCalculator)->providerCost(new TokenUsage(12, 0, 1056, 800), Rates::defaults());

    expect($g->status)->toBe(GenerationStatus::Succeeded)
        ->and($g->pixelforge_id)->toBe('gen_123')
        ->and($g->actual_provider_cost_nanos)->toBe($expectedProvider)
        ->and($g->actual_user_cost_nanos)->toBe(intdiv($expectedProvider * 125 + 99, 100))
        ->and($g->user->fresh()->credit_balance_nanos)->toBe(5_000_000_000 - $g->actual_user_cost_nanos)
        ->and(ledgerSum($g->user_id))->toBe($g->user->fresh()->credit_balance_nanos);
});

it('charges $0 for a failed generation and returns the hold', function () {
    $g = makeGeneration();
    postWebhook($this, webhookEvent('evt_f', 'generation.failed', [
        'id' => 'gen_123', 'status' => 'failed', 'metadata' => ['local_generation_id' => $g->id],
        'error' => ['code' => 'content_policy_violation', 'message' => 'Blocked.'],
    ]))->assertNoContent();

    $g->refresh();
    expect($g->status)->toBe(GenerationStatus::Failed)
        ->and($g->actual_user_cost_nanos)->toBe(0)
        ->and($g->actual_provider_cost_nanos)->toBe(0)
        ->and($g->error_code)->toBe('content_policy_violation')
        ->and($g->user->fresh()->credit_balance_nanos)->toBe(5_000_000_000)
        ->and(LedgerEntry::where('generation_id', $g->id)->where('type', 'charge')->exists())->toBeFalse();
});

it('charges only once when the exact same event is delivered twice', function () {
    $g = makeGeneration();
    $event = webhookEvent('evt_dup', 'generation.succeeded', succeededObject($g));

    postWebhook($this, $event)->assertNoContent();
    $balanceAfterFirst = $g->user->fresh()->credit_balance_nanos;
    postWebhook($this, $event)->assertNoContent();

    expect($g->user->fresh()->credit_balance_nanos)->toBe($balanceAfterFirst)
        ->and(LedgerEntry::where('generation_id', $g->id)->where('type', 'charge')->count())->toBe(1)
        ->and(WebhookEvent::where('event_id', 'evt_dup')->value('deliveries'))->toBe(2);
});

it('charges only once when two *different* success events arrive for one generation', function () {
    $g = makeGeneration();

    postWebhook($this, webhookEvent('evt_a', 'generation.succeeded', succeededObject($g)))->assertNoContent();
    postWebhook($this, webhookEvent('evt_b', 'generation.succeeded', succeededObject($g, usage: ['output_tokens' => 9999])))->assertNoContent();

    $g->refresh();
    expect(LedgerEntry::where('generation_id', $g->id)->where('type', 'charge')->count())->toBe(1)
        ->and($g->usage_snapshot['output_tokens'])->toBe(1056); // first terminal event wins
});

it('never regresses: processing arriving after succeeded is ignored', function () {
    $g = makeGeneration();
    $meta = ['local_generation_id' => $g->id];

    postWebhook($this, webhookEvent('evt_s', 'generation.succeeded', succeededObject($g)))->assertNoContent();
    $balance = $g->user->fresh()->credit_balance_nanos;

    postWebhook($this, webhookEvent('evt_p', 'generation.processing', ['id' => 'gen_123', 'status' => 'processing', 'metadata' => $meta]))->assertNoContent();
    postWebhook($this, webhookEvent('evt_q', 'generation.queued', ['id' => 'gen_123', 'status' => 'queued', 'metadata' => $meta]))->assertNoContent();

    $g->refresh();
    expect($g->status)->toBe(GenerationStatus::Succeeded)
        ->and($g->status_rank)->toBe(3)
        ->and($g->user->fresh()->credit_balance_nanos)->toBe($balance);
});

it('never flips between terminal states: failed after succeeded is ignored', function () {
    $g = makeGeneration();

    postWebhook($this, webhookEvent('evt_s', 'generation.succeeded', succeededObject($g)))->assertNoContent();
    postWebhook($this, webhookEvent('evt_f', 'generation.failed', [
        'id' => 'gen_123', 'status' => 'failed', 'metadata' => ['local_generation_id' => $g->id],
        'error' => ['code' => 'x', 'message' => 'late failure'],
    ]))->assertNoContent();

    $g->refresh();
    expect($g->status)->toBe(GenerationStatus::Succeeded)->and($g->actual_user_cost_nanos)->toBeGreaterThan(0);
});

it('matches a webhook that arrives before the POST /v1/generations response, then ignores the late response', function () {
    $g = makeGeneration();
    expect($g->pixelforge_id)->toBeNull();

    // Webhook first: only metadata.local_generation_id links it to our row.
    postWebhook($this, webhookEvent('evt_early', 'generation.processing', [
        'id' => 'gen_early', 'status' => 'processing', 'metadata' => ['local_generation_id' => $g->id],
    ]))->assertNoContent();

    $g->refresh();
    expect($g->status)->toBe(GenerationStatus::Processing)->and($g->pixelforge_id)->toBe('gen_early');

    // Now the job runs and the POST response (status=queued) comes back late.
    Http::fake(['*/v1/generations' => Http::response(['id' => 'gen_early', 'status' => 'queued'], 201)]);
    $g->forceFill(['status' => 'submitting', 'status_rank' => 0])->save(); // simulate job reading pre-webhook state
    app(GenerationStateMachine::class)->apply(['id' => 'gen_early', 'status' => 'processing', 'metadata' => ['local_generation_id' => $g->id]]);
    app(GenerationStateMachine::class)->apply(['id' => 'gen_early', 'status' => 'queued']);

    expect($g->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('handles out-of-order delivery: succeeded, then processing, then a duplicate succeeded', function () {
    $g = makeGeneration();
    $meta = ['local_generation_id' => $g->id];

    postWebhook($this, webhookEvent('evt_3', 'generation.succeeded', succeededObject($g)))->assertNoContent();
    postWebhook($this, webhookEvent('evt_2', 'generation.processing', ['id' => 'gen_123', 'status' => 'processing', 'metadata' => $meta]))->assertNoContent();
    postWebhook($this, webhookEvent('evt_3', 'generation.succeeded', succeededObject($g)))->assertNoContent();

    $g->refresh();
    expect($g->status)->toBe(GenerationStatus::Succeeded)
        ->and(LedgerEntry::where('generation_id', $g->id)->pluck('type')->sort()->values()->all())->toBe(['charge', 'hold', 'release'])
        ->and(ledgerSum($g->user_id))->toBe($g->user->fresh()->credit_balance_nanos);
});

it('acknowledges events for unknown generations without side effects', function () {
    postWebhook($this, webhookEvent('evt_x', 'generation.succeeded', ['id' => 'gen_not_ours', 'status' => 'succeeded']))
        ->assertNoContent();
});
