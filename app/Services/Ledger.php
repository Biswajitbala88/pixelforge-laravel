<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every balance change goes through here: one ledger row + the matching balance
 * delta, atomically. The (generation_id, type) unique index makes generation
 * entries idempotent — a second hold/charge/release for the same generation is
 * ignored and the balance is left alone.
 *
 * Invariant: users.credit_balance_nanos == SUM(ledger_entries.amount_nanos).
 */
class Ledger
{
    public function topUp(User $user, int $nanos, string $description): void
    {
        $this->record($user->id, null, LedgerEntry::TOPUP, $nanos, $description);
    }

    public function hold(Generation $generation): bool
    {
        return $this->record($generation->user_id, $generation->id, LedgerEntry::HOLD,
            -$generation->estimated_user_cost_nanos, 'Hold for estimated cost');
    }

    /** Return whatever was held for this generation. No-op if nothing was held or it was already released. */
    public function releaseHold(Generation $generation, string $description): bool
    {
        $held = LedgerEntry::where('generation_id', $generation->id)->where('type', LedgerEntry::HOLD)->value('amount_nanos');

        if ($held === null) {
            return false;
        }

        return $this->record($generation->user_id, $generation->id, LedgerEntry::RELEASE, -$held, $description);
    }

    public function charge(Generation $generation, int $nanos): bool
    {
        return $this->record($generation->user_id, $generation->id, LedgerEntry::CHARGE, -$nanos, 'Charge for generation');
    }

    /** @return bool true if the entry was written, false if it already existed. */
    private function record(int $userId, ?string $generationId, string $type, int $amount, string $description): bool
    {
        return DB::transaction(function () use ($userId, $generationId, $type, $amount, $description) {
            $now = now();
            $inserted = DB::table('ledger_entries')->insertOrIgnore([
                'user_id' => $userId,
                'generation_id' => $generationId,
                'type' => $type,
                'amount_nanos' => $amount,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                return false;
            }

            if ($amount !== 0) {
                DB::table('users')->where('id', $userId)->increment('credit_balance_nanos', $amount);
            }

            return true;
        });
    }
}
