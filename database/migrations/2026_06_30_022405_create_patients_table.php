<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->unsignedBigInteger('mrn_sequence')->nullable();
            $table->string('medical_number')->nullable();
            $table->string('name');
            $table->string('phone');
            $table->date('date_of_birth')->nullable();
            $table->integer('age')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->text('chronic_diseases')->nullable();
            $table->text('allergies')->nullable();
            $table->text('surgeries')->nullable();
            $table->text('medical_history')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'mrn_sequence'], 'idx_patients_tenant_mrn_seq');
            $table->index(['tenant_id', 'phone', 'name'], 'idx_patients_tenant_phone_name');
            $table->unique(['tenant_id', 'medical_number'], 'uniq_patients_tenant_medical_number');
            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'phone'], 'ft_patients_name_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};