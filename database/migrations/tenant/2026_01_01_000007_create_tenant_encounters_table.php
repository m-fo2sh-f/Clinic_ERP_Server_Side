<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            
            // Decoupled linkage: Nullable for Solo walk-ins; references appointment in polyclinic
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            // Type & Status (Backed by PHP 8.1 Enums)
            $table->string('type')->default('walk_in');
            $table->string('status')->default('in_progress');

            // Clinical Core (FHIR Encounter Decoupling)
            $table->text('chief_complaint')->nullable();
            $table->text('clinical_examination')->nullable();
            $table->json('diagnosis')->nullable();
            $table->json('vitals')->nullable();
            $table->text('private_notes')->nullable();

            // Timestamps
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Performance Indexes
            $table->index(['branch_id', 'status', 'created_at'], 'idx_encounters_branch_status_created');
            $table->index(['branch_id', 'doctor_id', 'status'], 'idx_encounters_branch_doc_status');
            $table->index(['patient_id', 'created_at'], 'idx_encounters_patient_created');
            $table->index(['doctor_id', 'created_at'], 'idx_encounters_doctor_created');
            $table->index('appointment_id', 'idx_encounters_appointment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
