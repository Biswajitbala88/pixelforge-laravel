<?php

use App\Enums\GenerationStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response([], 500)]); // exercise the fallback rates
});

it('creates a generation, holds the server-computed estimate and queues submission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/generations', [
        'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => ['', 'https://x.test/a.png'],
    ])->assertRedirect();

    $g = Generation::sole();
    expect($g->status)->toBe(GenerationStatus::Submitting)
        ->and($g->reference_images)->toBe(['https://x.test/a.png'])
        ->and($g->rates_snapshot['output_tokens'])->toBe('40.000000000')
        ->and($user->fresh()->credit_balance_nanos)->toBe(5_000_000_000 - $g->estimated_user_cost_nanos);

    Queue::assertPushed(SubmitGenerationJob::class, fn ($job) => $job->generationId === $g->id);
});

it('refuses to generate when the balance cannot cover the estimate', function () {
    $user = User::factory()->create();
    $user->forceFill(['credit_balance_nanos' => 1_000])->save();

    $this->actingAs($user)->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high'])
        ->assertSessionHasErrors('balance');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('validates size, quality and reference image URLs', function () {
    $this->actingAs(User::factory()->create())->post('/generations', [
        'prompt' => '', 'size' => '999x999', 'quality' => 'ultra', 'reference_images' => ['not a url', 'b', 'c', 'd', 'e'],
    ])->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images', 'reference_images.0']);
});

it('tops up credits via a ledger entry', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['amount_usd' => 10])->assertRedirect();
    $this->actingAs($user)->post('/credits', ['amount_usd' => 1000])->assertSessionHasErrors('amount_usd');

    expect($user->fresh()->credit_balance_nanos)->toBe(15_000_000_000)
        ->and(ledgerSum($user->id))->toBe(15_000_000_000);
});

it('restricts the financial report to admins', function () {
    $admin = User::factory()->create(); // first user => admin
    $other = User::factory()->create();

    expect($admin->fresh()->is_admin)->toBeTrue()->and($other->fresh()->is_admin)->toBeFalse();
    $this->actingAs($other)->get('/admin/report')->assertForbidden();
    $this->actingAs($admin)->get('/admin/report')->assertOk();
});

it('reports revenue, provider cost and profit by UTC completion day and reconciles with /v1/usage', function () {
    config(['services.pixelforge.webhook_secret' => TEST_WEBHOOK_SECRET]);
    $admin = User::factory()->create();
    $g = makeGeneration($admin);
    postWebhook($this, webhookEvent('evt_s', 'generation.succeeded', array_merge(succeededObject($g), ['completed_at' => '2026-11-17T23:59:59Z'])));
    $g->refresh();

    Http::fake(['*/v1/usage*' => Http::response(['data' => [
        ['date' => '2026-11-17', 'cost_usd' => Money::nanosToUsd($g->actual_provider_cost_nanos), 'generations' => 1],
    ]])]);

    $this->actingAs($admin)
        ->get('/admin/report', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'admin/Report',
            'X-Inertia-Partial-Data' => 'reconciliation',
        ])
        ->assertOk()
        ->assertJsonPath('props.reconciliation.rows.0.day', '2026-11-17')
        ->assertJsonPath('props.reconciliation.rows.0.match', true);

    $this->actingAs($admin)->get('/admin/report')->assertInertia(fn ($page) => $page
        ->component('admin/Report', false)
        ->where('daily.0.day', '2026-11-17')
        ->where('daily.0.revenue', (string) $g->actual_user_cost_nanos)
        ->where('daily.0.profit', (string) ($g->actual_user_cost_nanos - $g->actual_provider_cost_nanos)));
});
