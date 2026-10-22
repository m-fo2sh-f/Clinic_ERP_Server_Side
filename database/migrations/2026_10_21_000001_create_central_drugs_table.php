<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('central_drugs', function (Blueprint $table) {
            $table->id();
            $table->string('trade_name');
            $table->text('active_ingredient')->nullable();
            $table->string('form', 100)->nullable();
            $table->string('strength', 100)->nullable();
            $table->timestamps();

            $table->index('trade_name', 'idx_central_drugs_trade_name');
            $table->fullText(['trade_name', 'active_ingredient'], 'ft_central_drugs_search');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('central_drugs');
    }
};
