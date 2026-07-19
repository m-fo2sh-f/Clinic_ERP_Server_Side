<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            // الاستراتيجية: slots_only, fifo_only, hybrid
            $table->string('queue_strategy')->default('hybrid'); 
            $table->integer('avg_appointment_duration')->default(15); // مدة الكشف بالدقائق
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_settings');
    }
};