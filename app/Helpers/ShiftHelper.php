<?php

namespace App\Helpers;

use Carbon\Carbon;

class ShiftHelper
{
    /**
     * Calculate 5 AM medical shift time boundary
     */
    public static function getShiftWindow(?string $date = null): array
    {
        if ($date) {
            $selectedDate = Carbon::parse($date);
            $startTime    = $selectedDate->copy()->startOfDay()->addHours(5); // Start at 5 AM
            $endTime      = $selectedDate->copy()->addDay()->startOfDay()->addHours(5); // End at next day 5 AM
        } else {
            $now = now();
            if ($now->hour < 5) {
                $startTime = now()->subDay()->startOfDay()->addHours(5); // Shift started yesterday 5 AM
                $endTime   = now()->startOfDay()->addHours(5);          // Shift ends today 5 AM
            } else {
                $startTime = now()->startOfDay()->addHours(5);          // Shift started today 5 AM
                $endTime   = now()->addDay()->startOfDay()->addHours(5); // Shift ends tomorrow 5 AM
            }
        }

        return [$startTime, $endTime];
    }
}