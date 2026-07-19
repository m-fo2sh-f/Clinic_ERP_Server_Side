<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClinicSetting extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['branch_id', 'queue_strategy', 'avg_appointment_duration'];
}