<?php

namespace Database\Factories;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Appointment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // id is automatically generated as UUID via HasUuids trait
            // tenant_id will be automatically populated via stancl/tenancy context
            // branch_id and patient_id should be provided in Seeder
            'appointment_time' => fake()->dateTimeBetween('now', '+1 month'),
            'type' => fake()->randomElement(['check_up','follow_up','consultation']),   
            'status' => fake()->randomElement(['Confirmed', 'Checked-in', 'Canceled', 'No-Show', 'Completed']),
        ];
    }
}
