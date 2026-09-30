<?php

namespace App\Models;

use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Encounter extends Model
{
    use HasUuids, HasFactory;

    protected $fillable = [
        'branch_id',
        'patient_id',
        'doctor_id',
        'appointment_id',
        'type',
        'status',
        'chief_complaint',
        'clinical_examination',
        'diagnosis',
        'vitals',
        'private_notes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'type'         => EncounterType::class,
            'status'       => EncounterStatus::class,
            'diagnosis'    => 'array',
            'vitals'       => 'array',
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
