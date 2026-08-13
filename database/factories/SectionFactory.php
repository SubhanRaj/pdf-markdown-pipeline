<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'department_id' => Department::factory(),
            'name'          => $name,
            'slug'          => \Illuminate\Support\Str::slug($name),
        ];
    }
}
