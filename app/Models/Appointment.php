<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = [
        'branch_id',
        'patient_id',
        'doctor_id',
        'appointment_time',
        'type',
        'status',
        'chief_complaint',
        'diagnosis',
        'clinical_examination',
        'blood_pressure',
        'weight',
        'temperature',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'           => AppointmentStatus::class,
            'type'             => AppointmentType::class,
            'appointment_time' => 'datetime',
            'weight'           => 'decimal:3',
            'temperature'      => 'decimal:1',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class);
    }

    public function liveQueue(): HasOne
    {
        return $this->hasOne(LiveQueue::class);
    }

    public function clinicSetting(): HasOne
    {
        return $this->hasOne(ClinicSetting::class);
    }
}