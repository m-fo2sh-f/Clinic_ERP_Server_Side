<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant; // 🔥 التريت المسؤول عن العزل التلقائي

class Branch extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'address']; 
}