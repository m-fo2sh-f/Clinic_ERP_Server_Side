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
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->foreignUuid('drug_id')->nullable()->constrained('drugs')->nullOnDelete();
            $table->string('drug_name');
            $table->string('dose');
            $table->string('frequency');
            $table->string('duration');
            $table->text('instruction')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['prescription_id', 'sort_order'], 'idx_rx_items_prescription_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
