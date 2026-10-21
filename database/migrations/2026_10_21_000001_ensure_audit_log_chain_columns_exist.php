<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Repair migration for schema drift on `audit_logs`.
     *
     * `correlation_id`, `previous_hash` and `entry_hash` were introduced by
     * 2026_10_16_000009_create_outbox_notifications_support_and_governance_tables.
     * A database that recorded that migration against an earlier revision of
     * the file never received the columns, so every audit write failed with
     * "Unknown column 'correlation_id'" — which the login flow turned into a
     * hard 500 before the OTP screen could open.
     *
     * The change is applied per column and only when the column is absent, so
     * it is safe on a healthy database, on one where only some columns are
     * missing, and on one where the Phase-4 migration is still pending.
     */
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $missingColumns = array_filter(
            ['correlation_id', 'previous_hash', 'entry_hash'],
            static fn (string $column): bool => ! Schema::hasColumn('audit_logs', $column),
        );

        if ($missingColumns !== []) {
            Schema::table('audit_logs', function (Blueprint $table) use ($missingColumns) {
                if (in_array('correlation_id', $missingColumns, true)) {
                    $table->string('correlation_id', 64)->nullable()->after('user_agent');
                }

                if (in_array('previous_hash', $missingColumns, true)) {
                    $table->string('previous_hash', 64)->nullable()->after('metadata');
                }

                if (in_array('entry_hash', $missingColumns, true)) {
                    $table->string('entry_hash', 64)->nullable()->after('previous_hash');
                }
            });
        }

        if (! Schema::hasIndex('audit_logs', ['entry_hash'])) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index('entry_hash');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty: the columns belong to
     * 2026_10_16_000009_create_outbox_notifications_support_and_governance_tables,
     * and its down() drops them. Dropping them here would break the rollback
     * of databases that were never missing them.
     */
    public function down(): void
    {
        //
    }
};
