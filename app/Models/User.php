<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int $credit_balance_nanos
 * @property bool $is_admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'credit_balance_nanos' => 'integer',
            'is_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // First account on a fresh install becomes the admin (also: `php artisan user:admin email`).
        static::creating(function (User $user) {
            if (! static::query()->exists()) {
                $user->is_admin = true;
            }
        });

        // The column default grants a $5.00 welcome credit; record it in the ledger
        // so SUM(ledger) == balance holds from the first row.
        static::created(function (User $user) {
            $balance = (int) static::whereKey($user->id)->value('credit_balance_nanos');

            if ($balance !== 0) {
                LedgerEntry::create([
                    'user_id' => $user->id,
                    'type' => LedgerEntry::TOPUP,
                    'amount_nanos' => $balance,
                    'description' => 'Welcome credit',
                ]);
            }
        });
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
