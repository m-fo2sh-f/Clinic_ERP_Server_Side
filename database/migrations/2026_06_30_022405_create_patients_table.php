<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary(); // معرف المريض UUID ليكون متوافق مع الـ Scalability مستقبلاً
            $table->string('tenant_id');

            $table->string('name'); 
            $table->string('phone'); 
            $table->integer('age')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->text('medical_history')->nullable(); // أمراض مزمنة أو حساسية
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            
            // Search index prefixed with tenant_id for strict tenant isolation
            $table->index(['tenant_id', 'phone', 'name'], 'idx_patients_tenant_phone_name'); 
            $table->unique(['tenant_id', 'phone']);
            $table->fullText(['name', 'phone'], 'ft_patients_name_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};