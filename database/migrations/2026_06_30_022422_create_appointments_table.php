<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('appointment_time');
            $table->string('type')->default('check_up');
            $table->string('status')->default('booking');
            $table->text('chief_complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('clinical_examination')->nullable();
            $table->json('vitals')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'branch_id', 'status', 'appointment_time'], 'idx_appts_tenant_branch_status_time');
            $table->index(['tenant_id', 'doctor_id', 'appointment_time'], 'idx_appts_tenant_doctor_time');
            $table->index(['tenant_id', 'patient_id', 'status'], 'idx_appts_tenant_patient_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
