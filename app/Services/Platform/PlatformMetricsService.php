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
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('is_active', true)->count();

        // Calculate total doctors across all tenants
        $totalDoctors = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'doctor')
            ->where('model_has_roles.model_type', User::class)
            ->distinct('model_has_roles.model_id')
            ->count('model_has_roles.model_id');

        $totalAppointments = Appointment::count();
        $todayAppointments = Appointment::whereDate('appointment_time', today())->count();

        return [
            'total_tenants'      => $totalTenants,
            'active_tenants'     => $activeTenants,
            'total_doctors'      => $totalDoctors,
            'total_appointments' => $totalAppointments,
            'today_appointments' => $todayAppointments,
        ];
    }
}
