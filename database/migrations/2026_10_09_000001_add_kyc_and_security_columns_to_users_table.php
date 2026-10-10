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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('kyc_status', ['Not_Started', 'Verified', 'Failed'])->default('Not_Started');
            $table->enum('kyb_status', ['Not_Started', 'Pending', 'Verified', 'Rejected'])->default('Not_Started');
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->enum('role', ['user', 'admin'])->default('user');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'kyc_status',
                'kyb_status',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'last_login_ip',
                'role',
            ]);
        });
    }
};
