<?php

namespace App\Enums;

enum ClinicMode: string
{
    case SOLO       = 'solo';
    case POLYCLINIC = 'polyclinic';

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
