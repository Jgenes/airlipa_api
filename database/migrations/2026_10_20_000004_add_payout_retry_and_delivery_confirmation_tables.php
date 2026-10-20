<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 3: payout retry queue metadata (exponential backoff + escalation)
     * and single-use delivery-confirmation tokens for the QR release handshake.
     */
    public function up(): void
    {
        Schema::table('payout_instructions', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('executed_at');
            $table->unsignedInteger('retry_count')->default(0)->after('metadata');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
            $table->string('last_failure_reason', 500)->nullable()->after('next_retry_at');
        });

        Schema::create('delivery_confirmation_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protected_payment_id')->constrained('protected_payments')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('purpose', 30)->default('DELIVERY_CONFIRMATION');
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, CONSUMED, EXPIRED
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['protected_payment_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_confirmation_tokens');

        Schema::table('payout_instructions', function (Blueprint $table) {
            $table->dropColumn(['metadata', 'retry_count', 'next_retry_at', 'last_failure_reason']);
        });
    }
};
