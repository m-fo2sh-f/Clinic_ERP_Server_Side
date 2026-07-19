<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant; // 🔥 التريت المسؤول عن العزل التلقائي

class Branch extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = ['name', 'address']; 

    public function appointments(){

        return $this->hasMany(Appointment::class);

    }
    

}