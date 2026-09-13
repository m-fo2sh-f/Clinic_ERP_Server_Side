<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method'); // cash, visa
            $table->string('transaction_reference')->nullable();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'invoice_id'], 'idx_payments_tenant_invoice');
            $table->index(['tenant_id', 'paid_at'], 'idx_payments_tenant_paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
