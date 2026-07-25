<?php

namespace App\Enums;

enum LiveQueueStatus: string
{
    case WAITING = 'waiting';
    case UNDER_EXAMINATION = 'under_examination';
    case COMPLETED = 'completed';

    /**
     * Get active queue statuses for branch queries.
     *
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::WAITING->value,
            self::UNDER_EXAMINATION->value,
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
