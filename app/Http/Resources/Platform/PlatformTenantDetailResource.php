<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Branch;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;

class PlatformTenantDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $domain = $this->relationLoaded('domains')
            ? $this->domains->first()?->domain
            : $this->domains()->first()?->domain;

        $branches = Branch::where('tenant_id', $this->id)->get();
        $totalAppointments = Appointment::where('tenant_id', $this->id)->count();
        $totalPatients = Patient::where('tenant_id', $this->id)->count();
        
        // Count doctors assigned to this tenant
        $totalDoctors = User::where('tenant_id', $this->id)
            ->whereHas('roles', function ($query) {
                $query->where('name', 'doctor');
            })
            ->count();

        return [
            'id'                       => $this->id,
            'clinic_name'              => $this->clinic_name ?? $this->id,
            'domain'                   => $domain ?? ($this->id . '.my-saas.test'),
            'is_active'                => (bool) ($this->is_active ?? true),
            'branches_count'           => $branches->count(),
            'active_branches_count'    => $branches->where('is_active', true)->count(),
            'total_appointments_count' => $totalAppointments,
            'total_patients_count'     => $totalPatients,
            'total_doctors_count'      => $totalDoctors,
            'branches'                 => $branches->map(function ($branch) {
                return [
                    'id'        => $branch->id,
                    'name'      => $branch->name,
                    'address'   => $branch->address,
                    'phone'     => $branch->phone,
                    'is_active' => (bool) ($branch->is_active ?? true),
                ];
            }),
            'created_at'               => $this->created_at?->toIso8601String(),
        ];
    }
}
