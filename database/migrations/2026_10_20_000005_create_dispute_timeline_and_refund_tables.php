<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4: dispute timeline/chat, one-appeal workflow and refund request tracking.
     */
    public function up(): void
    {
        Schema::table('dispute_cases', function (Blueprint $table) {
            $table->unsignedTinyInteger('appeal_count')->default(0)->after('appeal_deadline_at');
            $table->string('original_decision_outcome', 40)->nullable()->after('appeal_count');
            $table->text('appeal_reason')->nullable()->after('original_decision_outcome');
            $table->timestamp('appealed_at')->nullable()->after('appeal_reason');
            $table->foreignId('appealed_by_user_id')->nullable()->after('appealed_at')->constrained('users')->nullOnDelete();
            $table->string('case_file_path', 255)->nullable()->after('appealed_by_user_id');
            $table->timestamp('case_file_generated_at')->nullable()->after('case_file_path');
        });

        Schema::table('party_subledger_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('reduced_minor')->default(0)->after('refunded_minor');
        });

        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_case_id')->constrained('dispute_cases')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message_type', 24)->default('MESSAGE'); // MESSAGE, TIMELINE, DECISION, APPEAL, SYSTEM
            $table->string('author_role', 24)->nullable(); // BUYER, SELLER, MEDIATOR, SYSTEM
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['dispute_case_id', 'created_at']);
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('refund_id', 64)->unique();
            $table->string('refund_reference', 40)->unique();
            $table->foreignId('protected_payment_id')->constrained('protected_payments')->restrictOnDelete();
            $table->foreignId('escrow_contract_id')->nullable()->constrained('escrow_contracts')->nullOnDelete();
            $table->foreignId('dispute_case_id')->nullable()->constrained('dispute_cases')->nullOnDelete();
            $table->string('milestone_id', 64)->nullable();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('currency', 10)->default('TZS');
            $table->string('scope', 20)->default('PARTIAL'); // FULL, PARTIAL, MILESTONE
            $table->unsignedBigInteger('principal_minor');
            $table->unsignedBigInteger('fee_refund_minor')->default(0);
            $table->unsignedBigInteger('tax_refund_minor')->default(0);
            $table->unsignedBigInteger('total_refund_minor');
            $table->string('destination_method', 24)->default('ORIGINAL_METHOD');
            $table->string('status', 20)->default('REQUESTED'); // REQUESTED, SUCCEEDED, FAILED, REVERSED
            $table->string('provider_code', 50)->nullable();
            $table->string('provider_refund_reference', 120)->nullable();
            $table->unsignedBigInteger('ledger_journal_id')->nullable();
            $table->string('idempotency_key', 128);
            $table->text('reason');
            $table->string('failure_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['protected_payment_id', 'idempotency_key'], 'refund_request_idem_once');
            $table->index(['protected_payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');

        Schema::dropIfExists('dispute_messages');

        Schema::table('party_subledger_accounts', function (Blueprint $table) {
            $table->dropColumn('reduced_minor');
        });

        Schema::table('dispute_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appealed_by_user_id');
            $table->dropColumn([
                'appeal_count',
                'original_decision_outcome',
                'appeal_reason',
                'appealed_at',
                'case_file_path',
                'case_file_generated_at',
            ]);
        });
    }
};
