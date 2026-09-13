<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToTenant, HasUuids, HasFactory;

    protected $fillable = [
        'invoice_number',
        'appointment_id',
        'patient_id',
        'branch_id',
        'subtotal',
        'discount',
        'total',
        'payment_status',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'       => 'decimal:2',
            'discount'       => 'decimal:2',
            'total'          => 'decimal:2',
            'payment_status' => PaymentStatus::class,
            'paid_at'        => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Recalculate invoice subtotal and total based on items.
     */
    public function recalculateTotals(): self
    {
        $subtotal = $this->items()->sum('total');
        $discount = (float) ($this->discount ?? 0);
        $total = max(0, (float) $subtotal - $discount);

        $this->update([
            'subtotal' => $subtotal,
            'total'    => $total,
        ]);

        return $this;
    }
}
