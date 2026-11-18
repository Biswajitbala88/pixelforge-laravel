<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    private const FRIENDLY_ERRORS = [
        'content_policy_violation' => 'Your prompt was blocked by the content policy. Try rephrasing it.',
        'validation_failed' => 'Pixelforge rejected the request as invalid.',
        'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again tomorrow.',
        'submission_failed' => 'We could not reach Pixelforge to start this generation.',
    ];

    public function toArray(Request $request): array
    {
        $expired = $this->imageExpired();

        return [
            'id' => $this->id,
            'pixelforge_id' => $this->pixelforge_id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?? [],
            // Money as strings: JS handles them with BigInt, no float rounding.
            'estimated_user_cost_nanos' => (string) $this->estimated_user_cost_nanos,
            'actual_user_cost_nanos' => $this->actual_user_cost_nanos === null ? null : (string) $this->actual_user_cost_nanos,
            'usage' => $this->usage_snapshot,
            'image_url' => $expired ? null : $this->image_url,
            'image_expired' => $this->image_url !== null && $expired,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_code ? (self::FRIENDLY_ERRORS[$this->error_code] ?? $this->error_message) : null,
            'error_detail' => $this->error_message,
            'retrying' => $this->submit_attempts > 0 && ! $this->isTerminal(),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
