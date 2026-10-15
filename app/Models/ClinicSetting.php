<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'queue_strategy',
        'avg_appointment_duration',
        'vitals_config',
        'prescription_template',
    ];

    protected function casts(): array
    {
        return [
            'vitals_config' => 'array',
            'prescription_template' => 'array',
            'avg_appointment_duration' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
