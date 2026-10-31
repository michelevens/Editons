<?php

use App\Models\Book;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * ADR — Journey friction fixes: the points where a new author, walking from
 * a first book to a finished one through the UI, stalled.
 */

// 1. Plan limit vs. import.

it('says on the register page how many books the free plan includes', function () {
    $this->get('/register')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('offer.books', config('billing.plans.free.books')));

    expect(json_decode(file_get_contents(lang_path('en.json')), true)['jfix.register_free_plan'])
        ->toContain('{books, plural');
});

it('offers an import into the existing book when the shelf is at its limit', function () {
    $user = User::factory()->create(['plan' => 'free']);
    $book = Book::factory()->for($user)->create(['title' => 'The Harbour Clinic']);

    $this->actingAs($user)->get('/books')
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_create', false)
            ->where('import_into.id', $book->id)
            ->where('import_into.title', 'The Harbour Clinic'));

    // The binder's import, which the shelf's offer opens, still takes a file.
    $file = Illuminate\Http\UploadedFile::fake()->createWithContent('more.md', "# Chapter Two\n\nMore words.\n");

    $this->actingAs($user)
        ->post("/books/{$book->id}/import", ['file' => $file], ['Accept' => 'application/json'])
        ->assertSuccessful();

    expect($book->sections()->where('title', 'Chapter Two')->exists())->toBeTrue()
        ->and($user->books()->count())->toBe(1);
});

it('sends no import target while another book can be made', function () {
    $user = User::factory()->subscribed('author')->create();
    Book::factory()->for($user)->create();

    $this->actingAs($user)->get('/books')
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_create', true)
            ->where('import_into', null));
});
