<?php

namespace Database\Factories;

use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ResearchSource>
 */
class ResearchSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'kind' => 'journal_article',
            'title' => $this->faker->sentence(4),
            'authors' => $this->faker->name(),
            'published' => (string) $this->faker->year(),
            // Default is unverified on purpose: a factory that produced
            // pre-approved sources would let a test pass while the real
            // verification path was broken.
            'verification' => 'unverified',
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'verification' => 'verified',
            'verified_at' => now(),
        ]);
    }
}
