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

            $table->index('mrn_sequence', 'idx_patients_mrn_seq');
            $table->index(['phone', 'name'], 'idx_patients_phone_name');
            $table->index('created_at', 'idx_patients_created');
            $table->unique('medical_number', 'uniq_patients_medical_number');
            $table->fullText(['name', 'phone'], 'ft_patients_name_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
