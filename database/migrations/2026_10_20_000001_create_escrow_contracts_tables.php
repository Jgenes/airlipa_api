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
        Schema::create('escrow_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_id', 64)->unique();
            $table->string('reference', 40)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('initiator_id')->constrained('users')->restrictOnDelete();
            $table->string('initiator_role', 10); // BUYER | SELLER
            $table->string('counterparty_name');
            $table->string('counterparty_phone', 30)->nullable();
            $table->string('counterparty_email', 190)->nullable();
            $table->foreignId('counterparty_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('category', 60)->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('amount_minor');
            $table->json('milestones')->nullable();
            $table->json('deliverables')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('delivery_deadline_at')->nullable();
            $table->unsignedInteger('inspection_hours')->default(72);
            $table->text('refund_terms')->nullable();
            $table->text('dispute_terms')->nullable();
            $table->string('fee_payer', 10)->default('BUYER'); // BUYER | SELLER | SPLIT
            $table->string('status', 20)->default('DRAFT'); // DRAFT, SHARED, ACCEPTED, REJECTED, EXPIRED, CANCELLED
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamp('acceptance_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamps();

            $table->index(['initiator_id', 'status']);
            $table->index(['counterparty_user_id', 'status']);
        });

        Schema::create('escrow_contract_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_contract_id')->constrained('escrow_contracts')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('terms');
            $table->string('terms_hash', 64);
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('CURRENT'); // CURRENT, PENDING_INITIATOR_APPROVAL, PENDING_COUNTERPARTY_APPROVAL, SUPERSEDED
            $table->timestamp('initiator_approved_at')->nullable();
            $table->timestamp('counterparty_approved_at')->nullable();
            $table->timestamps();

            $table->unique(['escrow_contract_id', 'version']);
        });

        Schema::create('contract_shares', function (Blueprint $table) {
            $table->id();
            $table->string('share_id', 64)->unique();
            $table->foreignId('escrow_contract_id')->constrained('escrow_contracts')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('channel', 20); // WHATSAPP, SMS, EMAIL, QR, LINK
            $table->string('recipient', 190)->nullable();
            $table->string('token', 160)->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status', 20)->default('CREATED'); // CREATED, DELIVERED, FAILED, OPENED
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->json('payload')->nullable();
            $table->string('failure_reason', 190)->nullable();
            $table->timestamps();

            $table->index(['escrow_contract_id', 'channel']);
        });

        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->string('signature_id', 64)->unique();
            $table->foreignId('escrow_contract_id')->constrained('escrow_contracts')->cascadeOnDelete();
            $table->foreignId('escrow_contract_version_id')->constrained('escrow_contract_versions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('signer_role', 20); // INITIATOR | COUNTERPARTY
            $table->string('full_name');
            $table->string('phone', 30);
            $table->string('email', 190)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('contract_hash', 64);
            $table->string('terms_version', 64);
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['escrow_contract_id', 'user_id', 'signer_role'], 'contract_signature_party_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_signatures');
        Schema::dropIfExists('contract_shares');
        Schema::dropIfExists('escrow_contract_versions');
        Schema::dropIfExists('escrow_contracts');
    }
};
