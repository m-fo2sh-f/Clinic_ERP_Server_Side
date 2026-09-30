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
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->string('transaction_reference')->nullable();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();

            $table->index('invoice_id', 'idx_payments_invoice');
            $table->index('paid_at', 'idx_payments_paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
