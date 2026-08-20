<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drugs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('trade_name');
            $table->text('active_ingredient')->nullable();
            $table->string('form')->nullable();
            $table->string('strength')->nullable();
            $table->string('company')->nullable();
            $table->decimal('price', 8, 2)->default(0.00);
            $table->string('therapeutic_class')->nullable();
            $table->string('barcode')->nullable();
            $table->timestamps();

            $table->index('trade_name', 'idx_drugs_trade_name');
            $table->index('barcode', 'idx_drugs_barcode');
            $table->fullText(['trade_name', 'active_ingredient'], 'ft_drugs_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drugs');
    }
};
