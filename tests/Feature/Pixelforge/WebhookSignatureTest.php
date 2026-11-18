<?php

use App\Models\WebhookEvent;

beforeEach(fn () => config(['services.pixelforge.webhook_secret' => TEST_WEBHOOK_SECRET]));

it('accepts a correctly signed webhook', function () {
    postWebhook($this, ['id' => 'evt_ping', 'type' => 'ping', 'data' => []])->assertNoContent();

    expect(WebhookEvent::where('event_id', 'evt_ping')->whereNotNull('processed_at')->exists())->toBeTrue();
});

it('rejects a tampered payload', function () {
    $event = ['id' => 'evt_1', 'type' => 'ping', 'data' => []];
    $tampered = json_encode(['id' => 'evt_1', 'type' => 'ping', 'data' => ['evil' => true]]);

    postWebhook($this, $event, rawOverride: $tampered)->assertStatus(400);
    expect(WebhookEvent::count())->toBe(0);
});

it('rejects a signature made with the wrong secret', function () {
    postWebhook($this, ['id' => 'evt_1', 'type' => 'ping'], secret: 'whsec_wrong')->assertStatus(400);
});

it('rejects an expired timestamp (replay protection)', function () {
    postWebhook($this, ['id' => 'evt_1', 'type' => 'ping'], timestamp: time() - 301)->assertStatus(400);
});

it('rejects a missing signature header', function () {
    $this->postJson('/webhooks/pixelforge', ['id' => 'evt_1', 'type' => 'ping'])->assertStatus(400);
});

it('rejects everything when no secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => '']);
    postWebhook($this, ['id' => 'evt_1', 'type' => 'ping'], secret: '')->assertStatus(400);
});
