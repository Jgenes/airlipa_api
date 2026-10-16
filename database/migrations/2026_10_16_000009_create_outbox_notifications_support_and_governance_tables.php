<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('aggregate_type', 64); // PROTECTED_PAYMENT, DISPUTE_CASE, PAYOUT_INSTRUCTION
            $table->string('aggregate_id', 64);
            $table->string('event_type', 64);
            $table->json('payload');
            $table->string('status', 24)->default('PUBLISHED'); // PENDING, PUBLISHED, FAILED
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('outbox_event_id')->nullable()->constrained('outbox_events')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('title_en', 160);
            $table->text('message_en');
            $table->string('title_sw', 160);
            $table->text('message_sw');
            $table->json('metadata')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('protected_payment_id')->nullable()->constrained('protected_payments')->nullOnDelete();
            $table->foreignId('dispute_case_id')->nullable()->constrained('dispute_cases')->nullOnDelete();
            $table->string('category', 48); // PAYMENT_DELAY, RELEASE_HELP, DISPUTE_ESCALATION, PAYOUT_INQUIRY, OTHER
            $table->string('priority', 16)->default('NORMAL'); // LOW, NORMAL, HIGH, URGENT
            $table->string('subject', 160);
            $table->string('status', 24)->default('OPEN'); // OPEN, IN_PROGRESS, RESOLVED, CLOSED
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_staff_reply')->default(false);
            $table->text('message');
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('approval_code', 64)->unique();
            $table->string('action_type', 64); // FEE_RULE_UPSERT, RECONCILIATION_EXCEPTION_RESOLVE, PROVIDER_STATUS_CHANGE
            $table->string('target_type', 64);
            $table->string('target_identifier', 64);
            $table->json('proposed_payload');
            $table->text('reason');
            $table->string('status', 24)->default('PENDING'); // PENDING, APPROVED, REJECTED
            $table->foreignId('maker_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('checker_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('checker_notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'action_type']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('user_agent');
            $table->string('previous_hash', 64)->nullable()->after('metadata');
            $table->string('entry_hash', 64)->nullable()->index()->after('previous_hash');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['correlation_id', 'previous_hash', 'entry_hash']);
        });

        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('outbox_events');
    }
};
