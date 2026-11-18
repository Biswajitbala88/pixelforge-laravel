<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGenerationJob;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function runJob(string $id): SubmitGenerationJob
{
    $job = (new SubmitGenerationJob($id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('posts only allowed fields with auth, idempotency key and local id metadata', function () {
    $g = makeGeneration();
    config(['services.pixelforge.api_key' => 'pf_test_key']);
    app()->forgetInstance(PixelforgeClient::class);
    Http::fake(['*/v1/generations' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 201)]);

    runJob($g->id);

    Http::assertSent(function (Request $r) use ($g) {
        return str_ends_with($r->url(), '/v1/generations')
            && $r->hasHeader('Authorization', 'Bearer pf_test_key')
            && $r->hasHeader('Idempotency-Key', $g->idempotency_key)
            && array_keys($r->data()) === ['prompt', 'size', 'quality', 'metadata'] // no empty reference_images
            && $r['metadata']['local_generation_id'] === $g->id;
    });

    expect($g->fresh())->status->toBe(GenerationStatus::Queued)->pixelforge_id->toBe('gen_1');
});

it('releases the job with Retry-After on 429/503 and keeps the generation pending', function (int $status, string $code) {
    $g = makeGeneration();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code, 'message' => 'slow down']], $status, ['Retry-After' => '7'])]);

    $job = runJob($g->id);

    $job->assertReleased();
    expect($g->fresh())->status->toBe(GenerationStatus::Submitting)->submit_attempts->toBe(1);
})->with([
    'rate limit' => [429, 'rate_limit_exceeded'],
    'concurrency' => [429, 'concurrency_limit_exceeded'],
    'overloaded' => [503, 'overloaded'],
]);

it('fails without retry on content policy / validation / daily limit, and refunds the hold', function (int $status, string $code) {
    $g = makeGeneration();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code, 'message' => 'nope']], $status)]);

    $job = runJob($g->id);

    $job->assertNotReleased();
    $g->refresh();
    expect($g->status)->toBe(GenerationStatus::Failed)
        ->and($g->error_code)->toBe($code)
        ->and($g->actual_user_cost_nanos)->toBe(0)
        ->and($g->user->fresh()->credit_balance_nanos)->toBe(5_000_000_000);
})->with([
    'content policy' => [400, 'content_policy_violation'],
    'validation' => [422, 'validation_failed'],
    'daily limit' => [429, 'daily_limit_exceeded'],
]);

it('gives up after the max number of provider retries and refunds', function () {
    $g = makeGeneration();
    $g->forceFill(['submit_attempts' => 5])->save();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    runJob($g->id)->assertNotReleased();

    expect($g->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($g->user->fresh()->credit_balance_nanos)->toBe(5_000_000_000);
});

it('waits locally (without calling Pixelforge) when our in-flight cap is reached', function () {
    config(['services.pixelforge.max_in_flight' => 1]);
    $busy = makeGeneration();
    $busy->forceFill(['status' => 'processing', 'status_rank' => 2])->save();
    $g = makeGeneration($busy->user);
    Http::fake();

    runJob($g->id)->assertReleased();
    Http::assertNothingSent();
});

it('does nothing if a webhook already moved the generation past submitting', function () {
    $g = makeGeneration();
    $g->forceFill(['status' => 'processing', 'status_rank' => 2])->save();
    Http::fake();

    runJob($g->id);
    Http::assertNothingSent();
});
