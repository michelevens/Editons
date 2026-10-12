<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ManuscriptSection> */
class ManuscriptSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'type' => 'chapter',
            'title' => fake()->sentence(3),
            'content' => null,
            'word_count' => 0,
            'position' => ManuscriptSection::POSITION_STEP,
        ];
    }
}
