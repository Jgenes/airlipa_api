<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Subledger layer: per-contract, per-milestone and per-party detail that
     * supports the GL control accounts. All buckets are integer minor units.
     * Entries (subledger_entries) are append-only; corrections are reversals.
     */
    public function up(): void
    {
        Schema::create('contract_subledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 64)->unique();
            $table->foreignId('escrow_contract_id')->constrained('escrow_contracts')->restrictOnDelete()->unique();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->restrictOnDelete();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('deposited_minor')->default(0);
            $table->unsignedBigInteger('available_minor')->default(0);
            $table->unsignedBigInteger('held_milestones_minor')->default(0);
            $table->unsignedBigInteger('frozen_minor')->default(0);
            $table->unsignedBigInteger('pending_release_minor')->default(0);
            $table->unsignedBigInteger('pending_refund_minor')->default(0);
            $table->unsignedBigInteger('released_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedBigInteger('fees_minor')->default(0);
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamps();
        });

        Schema::create('milestone_subledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 64)->unique();
            $table->foreignId('milestone_id')->constrained('protected_payment_milestones')->cascadeOnDelete()->unique();
            $table->foreignId('escrow_contract_id')->nullable()->constrained('escrow_contracts')->restrictOnDelete();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('funded_minor')->default(0);
            $table->unsignedBigInteger('available_minor')->default(0);
            $table->unsignedBigInteger('frozen_minor')->default(0);
            $table->unsignedBigInteger('pending_release_minor')->default(0);
            $table->unsignedBigInteger('released_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamps();
        });

        Schema::create('party_subledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_id', 64)->unique();
            $table->foreignId('escrow_contract_id')->constrained('escrow_contracts')->cascadeOnDelete();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 10); // BUYER | SELLER
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('funded_minor')->default(0);
            $table->unsignedBigInteger('entitled_minor')->default(0);
            $table->unsignedBigInteger('held_minor')->default(0);
            $table->unsignedBigInteger('paid_out_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamps();

            $table->unique(['escrow_contract_id', 'user_id', 'role'], 'party_subledger_party_once');
        });

        Schema::create('dispute_holds', function (Blueprint $table) {
            $table->id();
            $table->string('hold_id', 64)->unique();
            $table->foreignId('escrow_contract_id')->nullable()->constrained('escrow_contracts')->restrictOnDelete();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->restrictOnDelete();
            $table->foreignId('dispute_case_id')->nullable()->constrained('dispute_cases')->restrictOnDelete();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, RELEASED, REFUNDED, SPLIT
            $table->timestamp('frozen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->json('resolution')->nullable();
            $table->timestamps();

            $table->index(['protected_payment_id', 'status']);
        });

        Schema::create('fee_records', function (Blueprint $table) {
            $table->id();
            $table->string('fee_record_id', 64)->unique();
            $table->foreignId('escrow_contract_id')->nullable()->constrained('escrow_contracts')->restrictOnDelete();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->restrictOnDelete();
            $table->foreignId('fee_quote_id')->nullable()->constrained('fee_quotes')->restrictOnDelete();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('platform_fee_minor')->default(0);
            $table->unsignedBigInteger('provider_charge_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->string('borne_by', 10)->default('BUYER'); // BUYER | SELLER | SPLIT
            $table->string('status', 20)->default('DEFERRED'); // DEFERRED, EARNED, WAIVED
            $table->timestamp('swept_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id', 64)->unique();
            $table->string('subledger_type', 20); // CONTRACT, MILESTONE, PARTY, DISPUTE_HOLD, FEE, PAYOUT, REFUND
            $table->unsignedBigInteger('subledger_id');
            $table->string('bucket', 40);
            $table->string('direction', 10); // INCREASE | DECREASE
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->foreignId('ledger_journal_id')->nullable()->constrained('ledger_journals')->restrictOnDelete();
            $table->string('reference', 120)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subledger_type', 'subledger_id', 'bucket']);
        });

        Schema::create('balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_type', 30); // GL_ACCOUNT, CONTRACT_SUBLEDGER, PARTY_SUBLEDGER, MILESTONE_SUBLEDGER
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->date('as_of_date');
            $table->string('currency', 10)->default('TZS');
            $table->bigInteger('balance_minor');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['snapshot_type', 'subject_type', 'subject_id', 'as_of_date'], 'balance_snapshot_once_per_day');
        });

        Schema::create('suspense_items', function (Blueprint $table) {
            $table->id();
            $table->string('suspense_id', 64)->unique();
            $table->string('provider_code', 32)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('received_at');
            $table->string('status', 20)->default('UNMATCHED'); // UNMATCHED, MATCHED, REFUNDED, WRITTEN_OFF
            $table->foreignId('matched_contract_id')->nullable()->constrained('escrow_contracts')->nullOnDelete();
            $table->foreignId('matched_journal_id')->nullable()->constrained('ledger_journals')->nullOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspense_items');
        Schema::dropIfExists('balance_snapshots');
        Schema::dropIfExists('subledger_entries');
        Schema::dropIfExists('fee_records');
        Schema::dropIfExists('dispute_holds');
        Schema::dropIfExists('party_subledger_accounts');
        Schema::dropIfExists('milestone_subledger_accounts');
        Schema::dropIfExists('contract_subledger_accounts');
    }
};
