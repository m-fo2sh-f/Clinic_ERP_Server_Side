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
        Schema::create('doctor_drugs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('trade_name');
            $table->string('form', 100)->nullable();
            $table->string('strength', 100)->nullable();
            $table->text('active_ingredient')->nullable();
            $table->string('default_dosage')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('is_custom')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();

            $table->unique(['doctor_id', 'trade_name'], 'uq_doctor_drug_trade_name');
            $table->index(['doctor_id', 'usage_count'], 'idx_doctor_drug_usage');
            $table->index(['doctor_id', 'is_favorite'], 'idx_doctor_drug_favorite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_drugs');
    }
};
