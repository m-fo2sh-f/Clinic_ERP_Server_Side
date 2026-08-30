<?php

namespace Database\Factories;

use App\Models\LiveQueue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LiveQueue>
 */
class LiveQueueFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = LiveQueue::class;

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
            // branch_id, patient_id, appointment_id, queue_no will be provided in Seeder
            'status' => fake()->randomElement(\App\Enums\LiveQueueStatus::values()),
            'queue_no' => fake()->numberBetween(1, 100),
            'checked_in_at' => fake()->time('H:i:s'),
        ];
    }
}
