<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Drug extends Model
{
    use HasUuids, HasFactory;

    /**
     * Global drugs catalog — shared across all tenants.
     */
    protected $fillable = [
        'trade_name',
        'active_ingredient',
        'form',
        'strength',
        'company',
        'price',
        'therapeutic_class',
        'barcode',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    /**
     * Scope a query to search drugs by keyword across trade_name, active_ingredient, barcode, company.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('trade_name', 'like', "%{$term}%")
              ->orWhere('active_ingredient', 'like', "%{$term}%")
              ->orWhere('barcode', 'like', "%{$term}%")
              ->orWhere('company', 'like', "%{$term}%");
        });
    }

    public function scopeByForm(Builder $query, string $form): Builder
    {
        return $query->where('form', $form);
    }

    public function scopeByBarcode(Builder $query, string $barcode): Builder
    {
        return $query->where('barcode', $barcode);
    }
}
