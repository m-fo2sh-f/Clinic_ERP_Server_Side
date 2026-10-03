<?php

namespace App\Enums;

enum LiveQueueStatus: string
{
    case CHECKED_IN        = 'checked_in';
    case WAITING           = 'waiting';
    case UNDER_EXAMINATION = 'under_examination';
    case PENDING_PAYMENT   = 'pending_payment';
    case COMPLETED         = 'completed';
    case CANCELLED         = 'cancelled';
    case NO_SHOW           = 'no_show';

    /**
     * Get active queue statuses for branch queries.
     *
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::CHECKED_IN->value,
            self::WAITING->value,
            self::UNDER_EXAMINATION->value,
            self::PENDING_PAYMENT->value,
        ];
    }

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
