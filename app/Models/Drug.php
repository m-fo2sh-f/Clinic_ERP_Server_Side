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
     * Eliminates full table scans at scale using B-Tree prefix indexes and MySQL Full-Text search.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // 1. Purely numeric: Barcode prefix lookup using idx_drugs_barcode
        if (preg_match('/^[0-9]+$/', $term)) {
            return $query->where('barcode', 'LIKE', "{$term}%");
        }

        // 2. Short queries (< 3 chars): Prefix match on trade_name using idx_drugs_trade_name
        if (mb_strlen($term) < 3) {
            return $query->where('trade_name', 'LIKE', "{$term}%");
        }

        // 3. Queries >= 3 chars: Native MySQL Full-Text search
        $booleanQuery = self::formatBooleanQuery($term);

        if (empty($booleanQuery)) {
            return $query->where('trade_name', 'LIKE', "{$term}%");
        }

        return $query->whereRaw(
            "MATCH(trade_name, active_ingredient, barcode, company) AGAINST(? IN BOOLEAN MODE)",
            [$booleanQuery]
        );
    }

    /**
     * Sanitize input and build MySQL Full-Text Boolean query (+word*).
     */
    public static function formatBooleanQuery(string $term): string
    {
        // Strip problematic Boolean mode operators: + - * @ ~ < > ( ) " %
        $sanitized = preg_replace('/[+\-><()~*\"@%]+/', ' ', $term);
        $words = array_filter(explode(' ', trim((string) $sanitized)));

        if (empty($words)) {
            return '';
        }

        return implode(' ', array_map(fn ($word) => "+{$word}*", $words));
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
