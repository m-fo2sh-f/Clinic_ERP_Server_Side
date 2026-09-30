<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('encounter_id')->nullable()->constrained('encounters')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('prescription_code');
            $table->date('prescription_date');
            $table->text('general_advice')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->timestamps();

            $table->unique('prescription_code', 'uniq_rx_code');
            $table->index(['patient_id', 'prescription_date'], 'idx_rx_patient_date');
            $table->index('doctor_id', 'idx_rx_doctor');
            $table->index('encounter_id', 'idx_rx_encounter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
