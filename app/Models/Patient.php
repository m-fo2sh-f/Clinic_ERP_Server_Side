<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Patient extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = [
        'medical_number',
        'name',
        'phone',
        'date_of_birth',
        'age',
        'gender',
        'blood_group',
        'chronic_diseases',
        'allergies',
        'surgeries',
        'medical_history',
        'mrn_sequence',

    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function prescriptions(): HasManyThrough
    {
        return $this->hasManyThrough(Prescription::class, Appointment::class);
    }

    public function directPrescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function liveQueues(): HasMany
    {
        return $this->hasMany(LiveQueue::class);
    }
}