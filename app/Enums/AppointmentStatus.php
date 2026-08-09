<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case BOOKING           = 'booking';
    case CHECKED_IN        = 'checked_in';
    case UNDER_EXAMINATION = 'under_examination';
    case COMPLETED         = 'completed';
    case NO_SHOW           = 'no_show';
    case CANCELLED         = 'canceled';

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
