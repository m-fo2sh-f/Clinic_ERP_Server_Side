<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->nullable();
            $table->decimal('default_price', 10, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'idx_services_active');
            $table->index('name', 'idx_services_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
