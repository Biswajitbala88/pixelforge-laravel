<?php

use App\Enums\GenerationStatus;
use Illuminate\Support\Facades\Http;

it('polls stale queued/processing generations and settles them', function () {
    $g = makeGeneration();
    $g->forceFill(['status' => 'processing', 'status_rank' => 2, 'pixelforge_id' => 'gen_stuck', 'updated_at' => now()->subMinutes(2)])->saveQuietly();

    Http::fake(['*/v1/generations/gen_stuck' => Http::response(succeededObject($g, 'gen_stuck'))]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($g->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('leaves fresh generations alone', function () {
    $g = makeGeneration();
    $g->forceFill(['status' => 'queued', 'status_rank' => 1, 'pixelforge_id' => 'gen_fresh'])->save();
    Http::fake();

    $this->artisan('generations:reconcile')->assertSuccessful();
    Http::assertNothingSent();
});
