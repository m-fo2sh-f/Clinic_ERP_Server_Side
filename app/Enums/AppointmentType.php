<?php

namespace App\Enums;

enum AppointmentType: string
{
    case CHECK_UP = 'check_up';
    case FOLLOW_UP = 'follow_up';
    case CONSULTATION = 'consultation';
    case PENDING = 'pending';

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
