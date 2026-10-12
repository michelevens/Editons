<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Book> */
class BookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'subtitle' => fake()->sentence(5),
            'author_name' => fake()->name(),
            'genre' => fake()->randomElement(['Nonfiction', 'Memoir', 'Technical']),
            'status' => 'draft',
            'word_count' => 0,
        ];
    }
}
