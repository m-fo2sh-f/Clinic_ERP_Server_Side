<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToDoctor
{
    /**
     * Boot the trait to automatically assign doctor_id on model creation.
     */
    public static function bootBelongsToDoctor(): void
    {
        static::creating(function ($model): void {
            if (empty($model->doctor_id) && Auth::check()) {
                $model->doctor_id = Auth::id();
            }
        });
    }

    /**
     * Get the doctor that owns this record.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * Scope a query to only include records belonging to a specific doctor.
     */
    public function scopeForDoctor(Builder $query, ?string $doctorId = null): Builder
    {
        $id = $doctorId ?? Auth::id();

        if ($id) {
            return $query->where($this->getTable().'.doctor_id', $id);
        }

        return $query;
    }
}
