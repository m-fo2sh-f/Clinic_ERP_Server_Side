<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\LiveQueue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Tenant Initialization
        $tenant1 = Tenant::create();
        $tenant1->domains()->create(['domain' => 'clinic1.my-saas.test']);

        $tenant2 = Tenant::create();
        $tenant2->domains()->create(['domain' => 'clinic2.my-saas.test']);

        $tenant1->run(function () {
            // Create Branches
            $maadiBranch = Branch::create(['name' => 'Maadi Branch']);
            $tagamoaBranch = Branch::create(['name' => 'Tagamoa Branch']);

            // Create Clinic Settings
            ClinicSetting::create([
                'branch_id' => $maadiBranch->id,
                'queue_strategy' => 'hybrid',
                'avg_appointment_duration' => 15,
            ]);

            ClinicSetting::create([
                'branch_id' => $tagamoaBranch->id,
                'queue_strategy' => 'hybrid',
                'avg_appointment_duration' => 15,
            ]);
        });
    }
}

    //         // 3. Realistic Patient Data
    //         // Creates 30 patients per tenant natively utilizing PatientFactory
    //         $patients = Patient::factory()->count(30)->create();

    //         // Run queue simulations for each branch
    //         $this->seedBranchAppointmentsAndQueue($maadiBranch, $patients);
    //         $this->seedBranchAppointmentsAndQueue($tagamoaBranch, $patients);
    //     });

    //     // 3. Seed Second Tenant
    //     $tenant2->run(function () {
    //         // Also give the second tenant some realistic patient data (30 fake patients)
    //         Patient::factory()->count(30)->create();
            
    //         // Setting up minimal branch config for completeness
    //         $branch = Branch::create(['name' => 'Zayed Branch']);
    //         ClinicSetting::create([
    //             'branch_id' => $branch->id,
    //             'queue_strategy' => 'hybrid',
    //             'avg_appointment_duration' => 15,
    //         ]);
    //     });
    // }

    // /**
    //  * Generates today's appointments and live queue simulation logic per branch.
    //  */
    // private function seedBranchAppointmentsAndQueue(Branch $branch, $patients): void
    // {
    //     $today = Carbon::today();
    //     $appointments = [];
        
    //     // 4. Today's Appointments Simulation
    //     // Starting exactly from 04:00 PM and distributing sequentially
    //     $time = $today->copy()->setTime(16, 0); 
        
    //     // Generate 4 Checked-in appointments
    //     for ($i = 0; $i < 4; $i++) {
    //         $appointments[] = Appointment::factory()->create([
    //             'branch_id' => $branch->id,
    //             'patient_id' => $patients->random()->id,
    //             'appointment_time' => $time->copy(),
    //             'status' => 'Checked-in',
    //         ]);
    //         $time->addMinutes(30);
    //     }
        
    //     // Generate 6 Confirmed appointments (patients haven't arrived yet)
    //     for ($i = 0; $i < 6; $i++) {
    //         Appointment::factory()->create([
    //             'branch_id' => $branch->id,
    //             'patient_id' => $patients->random()->id,
    //             'appointment_time' => $time->copy(),
    //             'status' => 'Confirmed',
    //         ]);
    //         $time->addMinutes(30);
    //     }
        
    //     // 5. Live Queue Simulation Flow
    //     $queueNo = 1;
        
    //     // Convert the 4 Checked-in appointments to the LiveQueue
    //     foreach ($appointments as $app) {
    //         LiveQueue::factory()->create([
    //             'branch_id' => $branch->id,
    //             'patient_id' => $app->patient_id,
    //             'appointment_id' => $app->id,
    //             'queue_no' => $queueNo,
    //             // Doctor Room Safety Check: Exactly ONE patient in the LiveQueue per branch is Under Examination
    //             'status' => ($queueNo === 1) ? 'Under Examination' : 'Waiting',
    //             'checked_in_at' => $app->appointment_time->copy()->subMinutes(rand(5, 15))->format('H:i:s'),
    //         ]);
    //         $queueNo++;
    //     }
        
    //     // Create 2 "Walk-in" patients (appointment_id is null)
    //     for ($i = 0; $i < 2; $i++) {
    //         LiveQueue::factory()->create([
    //             'branch_id' => $branch->id,
    //             'patient_id' => $patients->random()->id,
    //             'appointment_id' => null, // Represents Walk-in
    //             'queue_no' => $queueNo,
    //             'status' => 'Waiting',
    //             'checked_in_at' => Carbon::now()->subMinutes(rand(1, 10))->format('H:i:s'),
    //         ]);
    //         $queueNo++;
    //     }
    // }

