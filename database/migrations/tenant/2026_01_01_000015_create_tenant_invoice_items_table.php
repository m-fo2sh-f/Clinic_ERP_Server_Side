<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('item_name');
            $table->decimal('unit_price', 10, 2);
            $table->integer('quantity')->default(1);
            $table->decimal('total', 10, 2);
            $table->timestamps();

            $table->index('invoice_id', 'idx_invoice_items_inv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
