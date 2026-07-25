<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Appointment extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = ['branch_id', 'patient_id', 'appointment_time', 'type', 'status'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'type'   => AppointmentType::class,
        ];
    }

    public function patient(){

        return $this->belongsTo(Patient::class);

    }

    public function branch(){

        return $this->belongsTo(Branch::class);

    }
    public function liveQueues()
    {
        return $this->hasMany(LiveQueue::class);
    }

    public function clinicSetting()
    {
        return $this->hasOne(ClinicSetting::class);
    }


}