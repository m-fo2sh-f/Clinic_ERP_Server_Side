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
            $table->string('tenant_id');
            $table->string('invoice_number');
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->decimal('subtotal', 10, 2)->default(0.00);
            $table->decimal('discount', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2)->default(0.00);
            $table->string('payment_status')->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'invoice_number'], 'uniq_tenant_invoice_num');
            $table->index(['tenant_id', 'branch_id', 'payment_status'], 'idx_invoices_branch_status');
            $table->index(['tenant_id', 'patient_id'], 'idx_invoices_patient');
            $table->index(['tenant_id', 'appointment_id'], 'idx_invoices_appointment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
