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
        Schema::create('payment_provider_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('provider_code', 50)->unique();
            $table->string('provider_name');
            $table->string('legal_entity');
            $table->string('provider_type', 40); // MOBILE_MONEY, BANK, TANQR, SANDBOX
            $table->string('license_reference');
            $table->string('custody_account_reference');
            $table->json('supported_currencies');
            $table->unsignedBigInteger('min_amount_minor')->default(100);
            $table->unsignedBigInteger('max_amount_minor')->default(10000000000);
            $table->boolean('supports_protected_hold')->default(true);
            $table->boolean('supports_refund')->default(true);
            $table->boolean('supports_payout')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('webhook_secret');
            $table->string('approval_reference');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['provider_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_provider_capabilities');
    }
};
