<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Pixelforge test helpers
|--------------------------------------------------------------------------
*/

const TEST_WEBHOOK_SECRET = 'whsec_test_secret';

/** Create a generation exactly as the app would (estimate + hold), without running the submit job. */
function makeGeneration(?User $user = null, array $input = []): Generation
{
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response([], 500)]);

    $user ??= User::factory()->create();

    return app(GenerationService::class)->create($user, $input + [
        'prompt' => 'A lighthouse at dusk, oil painting',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ]);
}

/** @param array<string,mixed> $object Pixelforge generation object */
function webhookEvent(string $eventId, string $type, array $object): array
{
    return ['id' => $eventId, 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['object' => $object]];
}

function postWebhook(TestCase $test, array $event, ?int $timestamp = null, ?string $secret = null, ?string $rawOverride = null): TestResponse
{
    $raw = json_encode($event);
    $timestamp ??= time();
    $header = WebhookSignature::header($secret ?? TEST_WEBHOOK_SECRET, $timestamp, $raw);

    return $test->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $header,
    ], $rawOverride ?? $raw);
}

function succeededObject(Generation $g, string $pixelforgeId = 'gen_123', array $usage = []): array
{
    return [
        'id' => $pixelforgeId,
        'status' => 'succeeded',
        'metadata' => ['local_generation_id' => $g->id],
        'usage' => $usage + ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1056, 'reasoning_tokens' => 800],
        'image_url' => 'https://cdn.example.test/'.$pixelforgeId.'.png',
        'image_url_expires_at' => now()->addHour()->toIso8601String(),
        'completed_at' => now()->toIso8601String(),
    ];
}

function ledgerSum(int $userId): int
{
    return (int) LedgerEntry::where('user_id', $userId)->sum('amount_nanos');
}
