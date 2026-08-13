<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'department_id'      => Department::factory(),
            'title'              => $title,
            'slug'               => \Illuminate\Support\Str::slug($title),
            'document_type'      => 'other',
            'original_filename'  => 'document.pdf',
            'original_pdf_path'  => 'documents/'.fake()->uuid().'.pdf',
            'status'             => 'uploaded',
        ];
    }
}
