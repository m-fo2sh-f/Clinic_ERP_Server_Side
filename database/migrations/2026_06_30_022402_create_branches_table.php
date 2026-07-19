<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // ربط الفرع بالـ Tenant الرئيسي (UUID)
            $table->string('tenant_id');
            $table->string('name');
            $table->string('address')->nullable();
            $table->timestamps();

            //علاقة الـ Foreign Key مع جدول الـ tenants الأساسي للباكدج
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};