<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            // لو جاي من حجز مسبق بنربطه بيه، لو Walk-in بيفضل سطر الحجز نال
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            
            $table->integer('queue_no'); // رقم الدور الفعلي في الصالة (1، 2، 3...)
            // الحالات الحية داخل العيادة فقط: Waiting, Under Examination
            $table->string('status')->default('Waiting'); 
            $table->time('checked_in_at'); // وقت الحضور الفعلي للمقارنة وحساب الانتظار
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['branch_id', 'queue_no', 'created_at']); // منع تكرار رقم الدور لنفس الفرع في نفس اليوم
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_queues');
    }
};