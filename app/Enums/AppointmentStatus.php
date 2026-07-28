<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case BOOKING = 'booking';
    case COMPLETED = 'completed';
    case CHECKED_IN = 'checked_in';
    case NO_SHOW = 'no_show';
    case CANCELLED = 'canceled';

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
