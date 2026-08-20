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
            $table->string('tenant_id');
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->string('prescription_code');
            $table->date('prescription_date');
            $table->text('general_advice')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'prescription_code'], 'uniq_rx_tenant_code');
            $table->index(['tenant_id', 'patient_id', 'prescription_date'], 'idx_rx_tenant_patient_date');
            $table->index(['tenant_id', 'doctor_id'], 'idx_rx_tenant_doctor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
