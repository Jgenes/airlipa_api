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
        Schema::create('fulfillment_evidences', function (Blueprint $table) {
            $table->id();
            $table->string('evidence_id', 64)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('protected_payment_id')->constrained('protected_payments')->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('protected_payment_milestones')->nullOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('submitter_role', 30); // MERCHANT, BUYER, COURIER, DISPUTE_ANALYST
            $table->string('evidence_type', 50); // DISPATCH_PROOF, COURIER_TRACKING, DELIVERY_OTP_CONFIRMATION, DELIVERY_PHOTO_DOC, DIGITAL_COMPLETION, DISPUTE_EVIDENCE
            $table->string('courier_name', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->text('description');
            $table->string('file_path')->nullable();
            $table->string('file_mime', 80)->nullable();
            $table->string('file_hash', 64)->nullable(); // SHA-256 content hash for tamper detection
            $table->json('metadata')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['protected_payment_id', 'evidence_type']);
        });

        Schema::create('dispute_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_id', 64)->unique();
            $table->string('case_reference', 40)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('protected_payment_id')->constrained('protected_payments')->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('case_type', 50); // NON_DELIVERY, MATERIALLY_DIFFERENT, PARTIAL_DELIVERY, UNAUTHORIZED_PAYMENT, PROVIDER_COMPLAINT, FRAUD_INVESTIGATION
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('disputed_amount_minor');
            $table->text('reason');
            $table->string('status', 40)->default('OPEN_FROZEN'); // OPEN_FROZEN, UNDER_REVIEW, DECIDED, EXECUTED, APPEALED, CLOSED
            $table->string('primary_provider_code', 50)->nullable();
            $table->string('decision_outcome', 40)->nullable(); // FULL_REFUND_BUYER, FULL_RELEASE_MERCHANT, PARTIAL_SPLIT
            $table->unsignedBigInteger('release_to_merchant_minor')->default(0);
            $table->unsignedBigInteger('refund_to_buyer_minor')->default(0);
            $table->string('decision_reason_code', 64)->nullable();
            $table->text('decision_notes')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sla_deadline_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('appeal_deadline_at')->nullable();
            $table->timestamps();

            $table->index(['protected_payment_id', 'status']);
        });

        Schema::create('reconciliation_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('exception_id', 64)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->nullOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained('payment_intents')->nullOnDelete();
            $table->string('provider_code', 50)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('category', 64); // AMOUNT_MISMATCH, CURRENCY_MISMATCH, UNKNOWN_STATUS, DUPLICATE_PROVIDER_TXN, CONFLICTING_STATUS
            $table->string('severity', 20)->default('HIGH'); // LOW, MEDIUM, HIGH, CRITICAL
            $table->string('status', 30)->default('OPEN'); // OPEN, INVESTIGATING, RESOLVED
            $table->text('details');
            $table->json('metadata')->nullable();
            $table->timestamp('sla_due_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
        Schema::dropIfExists('dispute_cases');
        Schema::dropIfExists('fulfillment_evidences');
    }
};
