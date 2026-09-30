<?php

namespace App\Models;

use App\Enums\ClinicMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'clinic_mode',
        'queue_strategy',
        'avg_appointment_duration',
        'vitals_config',
    ];

    protected function casts(): array
    {
        return [
            'clinic_mode'              => ClinicMode::class,
            'vitals_config'            => 'array',
            'avg_appointment_duration' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}