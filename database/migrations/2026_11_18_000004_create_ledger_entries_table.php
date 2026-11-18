<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20); // topup | hold | charge | release
            $table->bigInteger('amount_nanos'); // +credit, -debit
            $table->string('description');
            $table->timestamps();

            // At most one hold/charge/release per generation. NULLs are distinct in both
            // MySQL and SQLite unique indexes, so top-ups (generation_id NULL) are unaffected.
            // This is the database-level guarantee that duplicate webhooks cannot double-charge.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
