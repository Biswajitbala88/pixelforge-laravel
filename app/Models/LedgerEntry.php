<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only. Never updated or deleted by application code. */
class LedgerEntry extends Model
{
    public const TOPUP = 'topup';

    public const HOLD = 'hold';

    public const CHARGE = 'charge';

    public const RELEASE = 'release';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_nanos' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
