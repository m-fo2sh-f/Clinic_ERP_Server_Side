<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class CentralDrug extends Model
{
    use CentralConnection;

    protected $table = 'central_drugs';

    protected $fillable = [
        'trade_name',
        'active_ingredient',
        'form',
        'strength',
    ];

    /**
     * Scope query to search trade names with prefix priority.
     */
    public function scopeSearch(Builder $query, string $term, int $limit = 15): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query->limit($limit);
        }

        return $query->where(function (Builder $sub) use ($term): void {
            $sub->where('trade_name', 'LIKE', "{$term}%")
                ->orWhere('trade_name', 'LIKE', "%{$term}%")
                ->orWhere('active_ingredient', 'LIKE', "%{$term}%");
        })
            ->orderByRaw('CASE WHEN trade_name LIKE ? THEN 1 WHEN trade_name LIKE ? THEN 2 ELSE 3 END', ["{$term}%", "%{$term}%"])
            ->orderBy('trade_name')
            ->limit($limit);
    }
}
