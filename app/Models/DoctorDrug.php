<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToDoctor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DoctorDrug extends Model
{
    use BelongsToDoctor;
    use HasUuids;

    protected $table = 'doctor_drugs';

    protected $fillable = [
        'doctor_id',
        'trade_name',
        'form',
        'strength',
        'active_ingredient',
        'default_dosage',
        'usage_count',
        'is_custom',
        'is_favorite',
    ];

    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
            'is_custom' => 'boolean',
            'is_favorite' => 'boolean',
        ];
    }

    /**
     * Increment usage counter atomically.
     */
    public function incrementUsage(int $amount = 1): self
    {
        $this->increment('usage_count', $amount);

        return $this;
    }
}
