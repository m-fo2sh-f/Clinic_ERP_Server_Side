<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Patient>
 */
class PatientFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Patient::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Egyptian mobile prefixes
        $prefixes = ['010', '011', '012', '015'];

        return [
            // tenant_id will be automatically populated via stancl/tenancy context
            // id is automatically generated as UUID via HasUuids trait
            'name' => fake('ar_EG')->name(),
            'phone' => fake()->randomElement($prefixes) . fake()->numerify('########'),
            'age' => fake()->numberBetween(1, 90),
            'gender' => fake()->randomElement(['Male', 'Female']),
            'medical_history' => fake()->optional(0.7)->sentence(6),
        ];
    }
}
