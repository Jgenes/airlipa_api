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
        Schema::table('protected_payments', function (Blueprint $table) {
            $table->foreignId('escrow_contract_id')->nullable()->after('payment_link_id')
                ->constrained('escrow_contracts')->nullOnDelete();

            $table->index(['escrow_contract_id', 'status'], 'protected_payments_contract_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('protected_payments', function (Blueprint $table) {
            $table->dropIndex('protected_payments_contract_status_index');
            $table->dropConstrainedForeignId('escrow_contract_id');
        });
    }
};
