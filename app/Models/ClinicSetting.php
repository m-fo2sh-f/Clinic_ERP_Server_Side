<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class ClinicSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['branch_id', 'queue_strategy', 'avg_appointment_duration'];
}