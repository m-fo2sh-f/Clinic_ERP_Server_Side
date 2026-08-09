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
     * Run the migrations: Add FULLTEXT index on patients (name, phone) for high-performance searching.
     */
    public function up(): void
    {
        if (!$this->hasIndex('patients', 'ft_patients_name_phone')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->fullText(['name', 'phone'], 'ft_patients_name_phone');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->hasIndex('patients', 'ft_patients_name_phone')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->dropFullText('ft_patients_name_phone');
            });
        }
    }
};
