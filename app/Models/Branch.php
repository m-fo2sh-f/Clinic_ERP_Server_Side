<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Branch extends Model
{
    use HasUuids, HasFactory;

    protected $fillable = ['name', 'address', 'phone', 'is_active']; 

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function appointments(){

        return $this->hasMany(Appointment::class);

    }
    
    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function branchServices()
    {
        return $this->hasMany(BranchService::class);
    }
}