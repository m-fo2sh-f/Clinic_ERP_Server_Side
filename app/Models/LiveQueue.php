<?php

namespace App\Models;

use App\Enums\LiveQueueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LiveQueue extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = [
        'branch_id',
        'shift_date',
        'patient_id',
        'appointment_id',
        'queue_no',
        'status',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LiveQueueStatus::class,
        ];
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}