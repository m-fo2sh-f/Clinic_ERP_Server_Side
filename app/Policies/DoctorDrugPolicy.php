<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DoctorDrug;
use App\Models\User;

class DoctorDrugPolicy
{
    /**
     * Determine whether the user can view any doctor drugs.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['doctor', 'clinic_owner']);
    }

    /**
     * Determine whether the user can view the doctor drug.
     */
    public function view(User $user, DoctorDrug $doctorDrug): bool
    {
        return (int) $user->id === (int) $doctorDrug->doctor_id;
    }

    /**
     * Determine whether the user can create doctor drugs.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['doctor', 'clinic_owner']);
    }

    /**
     * Determine whether the user can update the doctor drug.
     */
    public function update(User $user, DoctorDrug $doctorDrug): bool
    {
        return (int) $user->id === (int) $doctorDrug->doctor_id;
    }

    /**
     * Determine whether the user can delete the doctor drug.
     */
    public function delete(User $user, DoctorDrug $doctorDrug): bool
    {
        return (int) $user->id === (int) $doctorDrug->doctor_id;
    }
}
