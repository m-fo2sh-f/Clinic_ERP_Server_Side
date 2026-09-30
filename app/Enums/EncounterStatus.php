<?php

namespace App\Enums;

enum EncounterStatus: string
{
    case DRAFT       = 'draft';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED   = 'completed';
    case CANCELLED   = 'cancelled';

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
