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
        Schema::create('protected_payments', function (Blueprint $table) {
            $table->id();
            $table->string('protected_payment_id', 64)->unique();
            $table->string('reference', 40)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('fee_quote_id')->constrained('fee_quotes')->restrictOnDelete();
            $table->foreignId('payment_link_id')->nullable()->constrained('protected_payment_links')->nullOnDelete();
            $table->foreignId('merchant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('transaction_type', 40)->default('PROTECTED_PAYMENT');
            $table->string('title');
            $table->text('item_description');
            $table->string('delivery_method', 40)->default('COURIER');
            $table->json('release_conditions');
            $table->text('return_policy')->nullable();
            $table->string('terms_version', 64);
            $table->string('terms_hash', 64);

            // Exact monetary conservation fields (all in integer minor units)
            // Rule 36 / 4.3.6.C: F = P + R_L + R_Q + L + Q + V
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('principal_amount_minor');
            $table->unsignedBigInteger('buyer_fee_minor')->default(0);
            $table->unsignedBigInteger('buyer_tax_minor')->default(0);
            $table->unsignedBigInteger('merchant_fee_minor')->default(0);
            $table->unsignedBigInteger('merchant_tax_minor')->default(0);
            $table->unsignedBigInteger('total_payable_minor');
            $table->unsignedBigInteger('merchant_net_minor');

            $table->unsignedBigInteger('funded_amount_minor')->default(0); // F
            $table->unsignedBigInteger('protected_balance_minor')->default(0); // P
            $table->unsignedBigInteger('reserved_release_minor')->default(0); // R_L
            $table->unsignedBigInteger('reserved_refund_minor')->default(0); // R_Q
            $table->unsignedBigInteger('released_amount_minor')->default(0); // L
            $table->unsignedBigInteger('refunded_amount_minor')->default(0); // Q
            $table->unsignedBigInteger('reversed_amount_minor')->default(0); // V
            $table->unsignedBigInteger('disputed_amount_minor')->default(0); // D (0 <= D <= P)

            // Separate state machines per SASDS 3.6.7 / BPA-SBV-14
            $table->string('status', 40)->default('AWAITING_FUNDING');
            $table->string('fulfillment_status', 40)->default('PENDING');
            $table->string('dispute_status', 40)->default('NONE');
            $table->string('settlement_status', 40)->default('NOT_ELIGIBLE');

            // Handover OTP & lifecycle timestamps
            $table->string('handover_otp_hash')->nullable();
            $table->string('custody_provider_code', 50)->nullable();
            $table->string('custody_provider_reference', 100)->nullable();
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamp('delivery_deadline_at')->nullable();
            $table->timestamp('auto_release_at')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['buyer_id', 'status']);
        });

        Schema::create('protected_payment_milestones', function (Blueprint $table) {
            $table->id();
            $table->string('milestone_id', 64)->unique();
            $table->foreignId('protected_payment_id')->constrained('protected_payments')->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('released_amount_minor')->default(0);
            $table->unsignedBigInteger('refunded_amount_minor')->default(0);
            $table->string('status', 40)->default('PENDING'); // PENDING, EVIDENCE_SUBMITTED, RELEASED, REFUNDED, DISPUTED
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['protected_payment_id', 'sequence_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protected_payment_milestones');
        Schema::dropIfExists('protected_payments');
    }
};
