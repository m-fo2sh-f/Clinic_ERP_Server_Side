<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case UNPAID         = 'unpaid';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID           = 'paid';
    case REFUNDED       = 'refunded';

    /**
     * Get all values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
