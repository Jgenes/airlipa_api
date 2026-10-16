<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('intent_id', 64)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('protected_payment_id')->constrained('protected_payments')->restrictOnDelete();
            $table->foreignId('fee_quote_id')->constrained('fee_quotes')->restrictOnDelete();
            $table->foreignId('payer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payee_id')->constrained('users')->restrictOnDelete();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor'); // Total payable
            $table->unsignedBigInteger('principal_amount_minor');
            $table->unsignedBigInteger('buyer_fee_minor')->default(0);
            $table->unsignedBigInteger('buyer_tax_minor')->default(0);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->string('status', 40)->default('CREATED');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['payer_id', 'idempotency_key']);
            $table->index(['protected_payment_id', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('attempt_id', 64)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->cascadeOnDelete();
            $table->string('provider_code', 50);
            $table->string('payment_method', 40); // MOBILE_MONEY, BANK, TANQR, SANDBOX
            $table->string('payer_instrument', 100)->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor');
            $table->string('idempotency_key', 128);
            $table->string('provider_reference', 120)->nullable()->unique();
            $table->string('raw_provider_status', 64)->nullable();
            $table->string('normalized_status', 40)->default('SUBMITTED'); // SUBMITTED, PENDING, UNKNOWN, SUCCEEDED, FAILED
            $table->string('failure_reason')->nullable();
            $table->string('request_hash', 64);
            $table->json('provider_payload')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_intent_id', 'idempotency_key']);
            $table->index(['provider_code', 'normalized_status']);
        });

        Schema::create('provider_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('provider_code', 50);
            $table->string('provider_transaction_id', 120);
            $table->string('event_type', 64);
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->nullOnDelete();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor');
            $table->string('signature_key_id', 64)->nullable();
            $table->string('message_hash', 64);
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status', 40); // PROCESSED, DUPLICATE_IGNORED, MISMATCH_BLOCKED, REJECTED
            $table->json('raw_payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->index(['provider_code', 'provider_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_intents');
    }
};
