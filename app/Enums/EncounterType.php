<?php

namespace App\Enums;

enum EncounterType: string
{
    case WALK_IN   = 'walk_in';
    case CHECK_UP  = 'check_up';
    case FOLLOW_UP = 'follow_up';
    case EMERGENCY = 'emergency';

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
