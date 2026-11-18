<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Money is stored as integer nanodollars (1 USD = 1,000,000,000). Signed:
            // a generation whose actual cost exceeds its estimate may dip slightly below zero.
            $table->bigInteger('credit_balance_nanos')->default(5_000_000_000)->after('password');
            $table->boolean('is_admin')->default(false)->after('credit_balance_nanos');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['credit_balance_nanos', 'is_admin']);
        });
    }
};
