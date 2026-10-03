<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invoice_number');
            $table->foreignUuid('encounter_id')->nullable()->constrained('encounters')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->decimal('subtotal', 10, 2)->default(0.00);
            $table->decimal('discount', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2)->default(0.00);
            $table->string('payment_status')->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('invoice_number', 'uniq_invoice_num');
            $table->index(['branch_id', 'payment_status'], 'idx_invoices_branch_status');
            $table->index(['branch_id', 'payment_status', 'created_at'], 'idx_invoices_branch_status_created');
            $table->index('patient_id', 'idx_invoices_patient');
            $table->index('appointment_id', 'idx_invoices_appointment');
            $table->index('encounter_id', 'idx_invoices_encounter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
