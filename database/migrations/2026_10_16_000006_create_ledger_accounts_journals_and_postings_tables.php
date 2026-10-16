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
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 64)->unique();
            $table->string('account_name');
            $table->string('account_class', 40); // ASSET_CONTROL, LIABILITY, REVENUE, EXPENSE
            $table->string('normal_side', 10); // DEBIT, CREDIT
            $table->string('currency', 10)->default('TZS');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->id();
            $table->string('journal_id', 64)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->restrictOnDelete();
            $table->string('event_type', 64); // FUNDING_CONFIRMED, DISPUTE_FREEZE, DISPUTE_UNFREEZE, PROTECTED_RELEASE, PROTECTED_REFUND, REVERSAL
            $table->string('posting_rule_code', 64);
            $table->string('posting_rule_version', 32)->default('v1.0');
            $table->string('idempotency_key', 128)->unique();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('total_debit_minor');
            $table->unsignedBigInteger('total_credit_minor');
            $table->string('provider_reference', 120)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_journal_id')->nullable()->constrained('ledger_journals')->restrictOnDelete();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['protected_payment_id', 'event_type']);
        });

        Schema::create('ledger_postings', function (Blueprint $table) {
            $table->id();
            $table->string('posting_id', 64)->unique();
            $table->foreignId('ledger_journal_id')->constrained('ledger_journals')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('party_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 10); // DEBIT, CREDIT
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor');
            $table->string('narration')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['ledger_account_id', 'party_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_postings');
        Schema::dropIfExists('ledger_journals');
        Schema::dropIfExists('ledger_accounts');
    }
};
