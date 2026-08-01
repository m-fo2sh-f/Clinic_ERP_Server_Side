<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // 🔥 لتوليد الـ UUID تلقائياً
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Patient extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = ['name', 'phone', 'age', 'gender', 'medical_history'];

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function liveQueues()
    {
        return $this->hasMany(LiveQueue::class);
    }
}