<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends BaseTenant
{
    use HasDomains, HasUuids, HasFactory;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'is_active',
        ];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'tenant_id', 'id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id', 'id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'tenant_id', 'id');
    }

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'tenant_id', 'id');
    }
}