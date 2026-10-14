<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
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
            'patient_id' => Patient::factory(),
            'appointment_time' => fake()->dateTimeBetween('now', '+1 month'),
            'type' => fake()->randomElement(['check_up', 'follow_up', 'consultation']),
            'status' => fake()->randomElement(AppointmentStatus::values()),
        ];
    }
}
