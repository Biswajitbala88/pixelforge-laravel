<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Generated locally *before* calling Pixelforge, and sent as metadata, so a
            // webhook that beats the POST response can still be matched.
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();

            $table->string('status', 20)->default('submitting');
            // 0=submitting 1=queued 2=processing 3=succeeded|failed. Transitions only move up.
            $table->unsignedTinyInteger('status_rank')->default(0);
            $table->unsignedTinyInteger('submit_attempts')->default(0);

            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 20);
            $table->json('reference_images')->nullable();

            $table->json('rates_snapshot');
            $table->json('estimate_snapshot')->nullable();
            $table->json('usage_snapshot')->nullable();

            // All money in integer nanodollars.
            $table->bigInteger('estimated_provider_cost_nanos');
            $table->bigInteger('estimated_user_cost_nanos');
            $table->bigInteger('actual_provider_cost_nanos')->nullable();
            $table->bigInteger('actual_user_cost_nanos')->nullable();
            // What Pixelforge itself reported (if present); kept for reconciliation.
            $table->bigInteger('reported_provider_cost_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('pixelforge_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
