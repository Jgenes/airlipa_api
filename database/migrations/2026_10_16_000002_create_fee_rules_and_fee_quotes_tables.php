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
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('fee_rule_id', 64)->unique();
            $table->string('pricing_version', 64);
            $table->string('fee_code', 64);
            $table->string('fee_class', 64); // PROTECTION_SERVICE_FEE, PAYMENT_FEE, etc.
            $table->string('transaction_type', 40); // PROTECTED_PAYMENT, DIRECT_PAYMENT
            $table->string('payer', 30); // BUYER, MERCHANT
            $table->string('beneficiary', 40); // AIRLIPA, PROVIDER, TAX_AUTHORITY
            $table->string('calculation_method', 30); // FLAT, PERCENTAGE, TIERED
            $table->unsignedBigInteger('flat_amount_minor')->default(0);
            $table->unsignedInteger('rate_bps')->default(0); // basis points: 100 = 1.00%
            $table->unsignedBigInteger('min_fee_minor')->default(0);
            $table->unsignedBigInteger('max_fee_minor')->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->string('tax_code', 40)->default('TZ_VAT_18');
            $table->unsignedInteger('tax_rate_bps')->default(1800); // 1800 = 18.00%
            $table->string('refund_policy', 64);
            $table->string('recognition_policy', 64);
            $table->string('approval_reference');
            $table->text('disclosure_en');
            $table->text('disclosure_sw');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->string('status', 30)->default('ACTIVE');
            $table->timestamps();

            $table->index(['transaction_type', 'currency', 'status']);
        });

        Schema::create('fee_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_id', 64)->unique();
            $table->string('correlation_id', 64)->index();
            $table->string('pricing_version', 64);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transaction_type', 40)->default('PROTECTED_PAYMENT');
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('principal_amount_minor');
            $table->unsignedBigInteger('buyer_fee_minor')->default(0);
            $table->unsignedBigInteger('buyer_tax_minor')->default(0);
            $table->unsignedBigInteger('merchant_fee_minor')->default(0);
            $table->unsignedBigInteger('merchant_tax_minor')->default(0);
            $table->unsignedBigInteger('total_payable_minor');
            $table->unsignedBigInteger('merchant_net_minor');
            $table->json('fee_lines');
            $table->json('approval_references');
            $table->string('terms_version', 64);
            $table->string('disclosure_hash', 64);
            $table->string('status', 30)->default('CREATED'); // CREATED, ACCEPTED, CONSUMED, EXPIRED
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['buyer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_quotes');
        Schema::dropIfExists('fee_rules');
    }
};
