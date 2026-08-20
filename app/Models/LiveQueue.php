<?php

namespace App\Models;

use App\Enums\LiveQueueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveQueue extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = [
        'branch_id',
        'doctor_id',
        'patient_id',
        'appointment_id',
        'shift_date',
        'queue_no',
        'status',
        'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'status'     => LiveQueueStatus::class,
            'shift_date' => 'date',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}