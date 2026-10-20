<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5: KYC/AML tiering on users, escrow ratings, reminder dispatch
     * dedupe, payout freeze control and daily analytics snapshots.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_tier', 30)->default('TIER_0')->after('kyb_status');
            $table->string('aml_risk_rating', 16)->default('LOW')->after('kyc_tier');
            $table->timestamp('tier_assessed_at')->nullable()->after('aml_risk_rating');

            $table->index(['kyc_tier', 'aml_risk_rating']);
        });

        Schema::create('escrow_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('rating_id', 64)->unique();
            $table->foreignId('escrow_contract_id')->constrained('escrow_contracts')->cascadeOnDelete();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->nullOnDelete();
            $table->foreignId('rater_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ratee_user_id')->constrained('users')->restrictOnDelete();
            $table->string('rater_role', 16); // BUYER | SELLER
            $table->unsignedTinyInteger('score'); // 1..5
            $table->text('comment')->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamps();

            $table->unique(['escrow_contract_id', 'rater_user_id'], 'escrow_rating_once_per_party');
            $table->index(['ratee_user_id', 'score']);
        });

        Schema::create('reminder_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('reminder_key', 160)->unique();
            $table->string('reminder_type', 48);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reminder_type', 'dispatched_at']);
        });

        Schema::create('payout_freeze_states', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 40)->default('GLOBAL'); // GLOBAL or provider code
            $table->boolean('is_active')->default(false);
            $table->string('reason', 500)->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique('scope');
        });

        Schema::create('partner_settlement_statements', function (Blueprint $table) {
            $table->id();
            $table->string('statement_reference', 80)->unique();
            $table->string('provider_code', 32)->default('SELCOM_TZ');
            $table->string('settlement_account_reference', 120);
            $table->string('currency', 3)->default('TZS');
            $table->date('statement_date');
            $table->bigInteger('opening_balance_minor')->default(0);
            $table->bigInteger('closing_balance_minor')->default(0);
            $table->bigInteger('total_inflow_minor')->default(0);
            $table->bigInteger('total_outflow_minor')->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->string('source', 24)->default('MANUAL'); // MANUAL, API, CSV_IMPORT
            $table->json('metadata')->nullable();
            $table->foreignId('ingested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider_code', 'statement_date']);
        });

        Schema::create('analytics_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('as_of_date')->unique();
            $table->unsignedInteger('contracts_created')->default(0);
            $table->unsignedInteger('contracts_accepted')->default(0);
            $table->unsignedInteger('deposits_confirmed')->default(0);
            $table->unsignedBigInteger('gross_escrow_volume_minor')->default(0);
            $table->unsignedBigInteger('released_volume_minor')->default(0);
            $table->unsignedBigInteger('refunded_volume_minor')->default(0);
            $table->unsignedInteger('disputes_opened')->default(0);
            $table->unsignedInteger('disputes_resolved')->default(0);
            $table->json('breakdown')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_snapshots');
        Schema::dropIfExists('partner_settlement_statements');
        Schema::dropIfExists('payout_freeze_states');
        Schema::dropIfExists('reminder_dispatches');
        Schema::dropIfExists('escrow_ratings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['kyc_tier', 'aml_risk_rating']);
            $table->dropColumn(['kyc_tier', 'aml_risk_rating', 'tier_assessed_at']);
        });
    }
};
