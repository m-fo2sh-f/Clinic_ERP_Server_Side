<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->date('shift_date')->nullable();
            $table->integer('queue_no');
            $table->string('status')->default('checked_in');
            $table->time('checked_in_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['tenant_id', 'branch_id', 'doctor_id', 'status', 'queue_no'], 'idx_queues_active_shift_doctor');
            $table->unique(['tenant_id', 'branch_id', 'doctor_id', 'shift_date', 'queue_no'], 'uniq_queues_branch_doc_shift_no');
            $table->index(['tenant_id', 'appointment_id'], 'idx_queues_tenant_appointment');
            $table->index(['tenant_id', 'patient_id'], 'idx_queues_tenant_patient');
            $table->index(['tenant_id', 'branch_id', 'created_at'], 'idx_queues_tenant_branch_created');
            $table->index(
                ['tenant_id', 'branch_id', 'status', 'created_at', 'queue_no'], 
                'idx_queues_active_shift_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_queues');
    }
};
