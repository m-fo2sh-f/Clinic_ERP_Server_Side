<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class LiveQueue extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['branch_id', 'patient_id', 'appointment_id', 'queue_no', 'status', 'checked_in_at'];
}