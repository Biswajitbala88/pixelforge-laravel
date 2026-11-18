<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $user_id
 * @property GenerationStatus $status
 * @property int $status_rank
 * @property int $estimated_user_cost_nanos
 */
class Generation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'status_rank' => 'integer',
            'submit_attempts' => 'integer',
            'reference_images' => 'array',
            'rates_snapshot' => 'array',
            'estimate_snapshot' => 'array',
            'usage_snapshot' => 'array',
            'estimated_provider_cost_nanos' => 'integer',
            'estimated_user_cost_nanos' => 'integer',
            'actual_provider_cost_nanos' => 'integer',
            'actual_user_cost_nanos' => 'integer',
            'reported_provider_cost_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'pixelforge_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** Set status and its rank together so they can never disagree. */
    public function setStatus(GenerationStatus $status): void
    {
        $this->status = $status;
        $this->status_rank = $status->rank();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function imageExpired(): bool
    {
        return $this->image_url_expires_at !== null && $this->image_url_expires_at->isPast();
    }
}
