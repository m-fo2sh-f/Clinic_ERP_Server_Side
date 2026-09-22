<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClinicSetting extends Model
{
    use HasFactory;

    protected $fillable = ['branch_id', 'queue_strategy', 'avg_appointment_duration'];
}