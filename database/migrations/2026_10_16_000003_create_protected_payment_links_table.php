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
        Schema::create('protected_payment_links', function (Blueprint $table) {
            $table->id();
            $table->string('link_id', 64)->unique();
            $table->string('token', 96)->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('transaction_type', 40)->default('PROTECTED_PAYMENT');
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('principal_amount_minor');
            $table->string('delivery_method', 40)->default('COURIER');
            $table->unsignedInteger('delivery_days')->default(3);
            $table->unsignedInteger('auto_release_hours')->default(48);
            $table->json('release_conditions')->nullable();
            $table->text('return_policy')->nullable();
            $table->string('permitted_buyer_phone', 30)->nullable();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses_count')->default(0);
            $table->string('status', 30)->default('ACTIVE'); // ACTIVE, EXPIRED, REVOKED, EXHAUSTED
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protected_payment_links');
    }
};
