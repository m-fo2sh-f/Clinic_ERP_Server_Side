<?php

namespace App\Services\Platform;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PlatformMetricsService
{
    /**
     * Fetch aggregated platform metrics across all tenants with 30-minute caching.
     */
    public function getMetrics(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget('platform_aggregated_metrics');
        }

        return Cache::remember('platform_aggregated_metrics', now()->addMinutes(30), function () {
            $tenants = Tenant::all();
            $totalTenants = $tenants->count();
            $activeTenants = $tenants->where('is_active', true)->count();

            $totalDoctors = 0;
            $totalAppointments = 0;
            $todayAppointments = 0;

            $startOfToday = today()->startOfDay();
            $endOfToday = today()->endOfDay();

            foreach ($tenants as $tenant) {
                try {
                    $tenant->run(function () use (&$totalDoctors, &$totalAppointments, &$todayAppointments, $startOfToday, $endOfToday) {
                        $totalDoctors += User::role('doctor')->count();
                        $totalAppointments += Appointment::count();
                        $todayAppointments += Appointment::whereBetween('appointment_time', [$startOfToday, $endOfToday])->count();
                    });
                } catch (\Throwable) {
                    // Ignore any tenant that is unreachable
                }
            }

            return [
                'total_tenants' => $totalTenants,
                'active_tenants' => $activeTenants,
                'total_doctors' => $totalDoctors,
                'total_appointments' => $totalAppointments,
                'today_appointments' => $todayAppointments,
            ];
        });
    }
}
