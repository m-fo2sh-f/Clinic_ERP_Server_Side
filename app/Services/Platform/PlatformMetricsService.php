<?php

namespace App\Services\Platform;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlatformMetricsService
{
    /**
     * Fetch aggregated platform metrics across all tenants.
     */
    public function getMetrics(): array
    {
        $tenants = Tenant::all();
        $totalTenants = $tenants->count();
        $activeTenants = $tenants->where('is_active', true)->count();

        $totalDoctors = 0;
        $totalAppointments = 0;
        $todayAppointments = 0;

        foreach ($tenants as $tenant) {
            try {
                $tenant->run(function () use (&$totalDoctors, &$totalAppointments, &$todayAppointments) {
                    $totalDoctors += User::role('doctor')->count();
                    $totalAppointments += Appointment::count();
                    $todayAppointments += Appointment::whereDate('appointment_time', today())->count();
                });
            } catch (\Throwable) {
                // Ignore any tenant that is unreachable
            }
        }

        return [
            'total_tenants'      => $totalTenants,
            'active_tenants'     => $activeTenants,
            'total_doctors'      => $totalDoctors,
            'total_appointments' => $totalAppointments,
            'today_appointments' => $todayAppointments,
        ];
    }
}
