<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Helper to check if an index exists on a MySQL table.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }

    /**
     * Run the migrations: High-performance indexing and schema fixes for Multi-Tenant queue synchronization.
     */
    public function up(): void
    {
        // 1. Add shift_date to live_queues if not present
        if (!Schema::hasColumn('live_queues', 'shift_date')) {
            Schema::table('live_queues', function (Blueprint $table) {
                $table->date('shift_date')->after('branch_id')->nullable();
            });
        }

        // 2. Add appointments indexes if missing
        if (!$this->hasIndex('appointments', 'idx_appts_tenant_branch_status_time')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->index(['tenant_id', 'branch_id', 'status', 'appointment_time'], 'idx_appts_tenant_branch_status_time');
            });
        }

        if (!$this->hasIndex('appointments', 'idx_appts_tenant_patient_status')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->index(['tenant_id', 'patient_id', 'status'], 'idx_appts_tenant_patient_status');
            });
        }

        // 3. Add live_queues indexes if missing
        if (!$this->hasIndex('live_queues', 'idx_queues_active_shift')) {
            Schema::table('live_queues', function (Blueprint $table) {
                $table->index(['tenant_id', 'branch_id', 'status', 'queue_no'], 'idx_queues_active_shift');
            });
        }

        if (!$this->hasIndex('live_queues', 'uniq_queues_branch_shift_no')) {
            Schema::table('live_queues', function (Blueprint $table) {
                $table->unique(['tenant_id', 'branch_id', 'shift_date', 'queue_no'], 'uniq_queues_branch_shift_no');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->hasIndex('live_queues', 'uniq_queues_branch_shift_no')) {
            Schema::table('live_queues', function (Blueprint $table) {
                $table->dropUnique('uniq_queues_branch_shift_no');
            });
        }

        if ($this->hasIndex('live_queues', 'idx_queues_active_shift')) {
            Schema::table('live_queues', function (Blueprint $table) {
                $table->dropIndex('idx_queues_active_shift');
            });
        }

        if (Schema::hasColumn('live_queues', 'shift_date')) {
            Schema::table('live_queues', function (Blueprint $table) {
                $table->dropColumn('shift_date');
            });
        }

        if ($this->hasIndex('appointments', 'idx_appts_tenant_patient_status')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropIndex('idx_appts_tenant_patient_status');
            });
        }

        if ($this->hasIndex('appointments', 'idx_appts_tenant_branch_status_time')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropIndex('idx_appts_tenant_branch_status_time');
            });
        }
    }
};
