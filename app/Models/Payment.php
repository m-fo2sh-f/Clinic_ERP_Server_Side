<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = [
        'invoice_id',
        'cashier_id',
        'amount',
        'payment_method',
        'transaction_reference',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'paid_at'        => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
