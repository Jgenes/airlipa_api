<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('payout_account_id')->unique();
            $table->foreignId('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->string('account_type', 32)->default('MNO_WALLET'); // MNO_WALLET, BANK_ACCOUNT
            $table->string('provider_code', 32); // MPESA_TZ, TPESA_TZ, MIXX_TZ, AIRTEL_TZ, CRDB_BANK
            $table->string('account_name', 140);
            $table->string('account_number', 64);
            $table->string('account_number_hash', 64)->index();
            $table->string('currency', 3)->default('TZS');
            $table->boolean('is_default')->default(false);
            $table->string('verification_status', 24)->default('VERIFIED'); // PENDING, VERIFIED, SUSPENDED
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('cooling_off_until')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'is_default']);
        });

        Schema::create('settlement_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_reference', 64)->unique();
            $table->string('provider_code', 32);
            $table->string('currency', 3)->default('TZS');
            $table->unsignedInteger('instructions_count')->default(0);
            $table->bigInteger('total_amount_minor')->default(0);
            $table->string('status', 24)->default('COMPLETED'); // PROCESSING, COMPLETED, PARTIAL_FAILURE
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_instructions', function (Blueprint $table) {
            $table->id();
            $table->string('payout_instruction_id', 64)->unique();
            $table->foreignId('settlement_batch_id')->nullable()->constrained('settlement_batches')->nullOnDelete();
            $table->foreignId('protected_payment_id')->constrained('protected_payments')->restrictOnDelete();
            $table->foreignId('merchant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payout_account_id')->constrained('merchant_payout_accounts')->restrictOnDelete();
            $table->string('provider_code', 32);
            $table->string('currency', 3)->default('TZS');
            $table->bigInteger('gross_released_minor');
            $table->bigInteger('merchant_fee_minor')->default(0);
            $table->bigInteger('merchant_tax_minor')->default(0);
            $table->bigInteger('net_payout_minor');
            $table->string('idempotency_key', 100);
            $table->string('provider_payout_reference', 96)->nullable();
            $table->string('status', 24)->default('SETTLED'); // PENDING, SETTLED, FAILED, BLOCKED
            $table->foreignId('ledger_journal_id')->nullable()->constrained('ledger_journals')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'idempotency_key'], 'payout_merchant_idempotency_unique');
            $table->index(['protected_payment_id', 'status']);
        });

        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_reference', 64)->unique();
            $table->string('provider_code', 32);
            $table->string('statement_date', 16); // YYYY-MM-DD
            $table->string('currency', 3)->default('TZS');
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('matched_lines')->default(0);
            $table->unsignedInteger('exception_lines')->default(0);
            $table->bigInteger('statement_total_minor')->default(0);
            $table->bigInteger('ledger_clearing_balance_minor')->default(0);
            $table->bigInteger('protected_obligation_balance_minor')->default(0);
            $table->bigInteger('variance_minor')->default(0);
            $table->string('status', 32)->default('COMPLETED'); // COMPLETED, COMPLETED_WITH_EXCEPTIONS
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reconciliation_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->string('provider_code', 32);
            $table->string('provider_reference', 96);
            $table->string('attempt_reference', 64)->nullable();
            $table->string('direction', 16)->default('INFLOW'); // INFLOW, OUTFLOW, REFUND
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('TZS');
            $table->string('match_status', 32); // MATCHED, AMOUNT_MISMATCH, MISSING_IN_LEDGER, ORPHAN_PROVIDER_LINE
            $table->string('exception_code', 64)->nullable();
            $table->timestamps();

            $table->index(['provider_code', 'provider_reference'], 'recon_line_provider_ref_idx');
        });

        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('assessment_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->nullOnDelete();
            $table->string('checkpoint', 48); // AGREEMENT_CREATION, PAYMENT_INITIATION, RELEASE_EXECUTION, PAYOUT_CHANGE
            $table->unsignedInteger('risk_score')->default(0); // 0 - 100
            $table->string('decision', 24)->default('ALLOW'); // ALLOW, STEP_UP, REVIEW, BLOCK
            $table->json('triggered_rules')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'checkpoint']);
        });

        Schema::create('risk_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert_code', 64)->unique();
            $table->foreignId('risk_assessment_id')->nullable()->constrained('risk_assessments')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->nullOnDelete();
            $table->string('rule_code', 64); // VELOCITY_LIMIT, HIGH_VALUE_TXN, RAPID_RELEASE, SELF_DEALING_ATTEMPT
            $table->string('severity', 16)->default('MEDIUM'); // LOW, MEDIUM, HIGH, CRITICAL
            $table->string('status', 24)->default('OPEN'); // OPEN, CLEARED, CONFIRMED_FRAUD
            $table->text('details');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_alerts');
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('reconciliation_statement_lines');
        Schema::dropIfExists('reconciliation_runs');
        Schema::dropIfExists('payout_instructions');
        Schema::dropIfExists('settlement_batches');
        Schema::dropIfExists('merchant_payout_accounts');
    }
};
