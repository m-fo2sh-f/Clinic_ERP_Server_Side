<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->foreignUuid('drug_id')->nullable()->constrained('drugs')->nullOnDelete();
            $table->string('drug_name');
            $table->string('dose');
            $table->string('frequency');
            $table->string('duration');
            $table->text('instruction')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'prescription_id', 'sort_order'], 'idx_rx_items_tenant_prescription_order');
            $table->index(['tenant_id', 'drug_id'], 'idx_rx_items_tenant_drug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
