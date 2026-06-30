<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // 🔥 لتوليد الـ UUID تلقائياً
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Patient extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['name', 'phone', 'age', 'gender', 'medical_history'];
}