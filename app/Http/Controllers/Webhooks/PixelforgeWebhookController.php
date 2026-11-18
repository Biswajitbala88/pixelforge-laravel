<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\GenerationStateMachine;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationStateMachine $machine): Response|JsonResponse
    {
        $raw = $request->getContent();

        if (! $signature->verify($raw, $request->header('Pixelforge-Signature'))) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $payload = json_decode($raw, true);
        $eventId = is_array($payload) ? ($payload['id'] ?? null) : null;

        if (! is_string($eventId) || $eventId === '') {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        $type = (string) ($payload['type'] ?? 'unknown');

        // Record first (unique on event_id), so duplicates are cheap to detect.
        $now = now();
        $isNew = DB::table('webhook_events')->insertOrIgnore([
            'event_id' => $eventId,
            'event_type' => $type,
            'payload' => $raw,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;

        $event = WebhookEvent::where('event_id', $eventId)->firstOrFail();

        if (! $isNew) {
            $event->increment('deliveries');
        }

        if ($event->processed_at !== null) {
            return response()->noContent();
        }

        if (str_starts_with($type, 'generation.')) {
            $object = $payload['data']['object'] ?? $payload['data'] ?? [];
            $hint = GenerationStatus::tryFrom(substr($type, strlen('generation.')));

            if ($machine->apply(is_array($object) ? $object : [], $hint) === null) {
                Log::warning('Pixelforge webhook for unknown generation', ['event_id' => $eventId, 'type' => $type]);
            }
        }
        // `ping` and unknown event types are acknowledged and recorded without side effects.

        // If two copies raced past the processed_at check, the state machine + ledger
        // unique index already made the second a no-op; this just records completion.
        $event->forceFill(['processed_at' => now()])->save();

        return response()->noContent();
    }
}
