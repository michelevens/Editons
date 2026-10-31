<?php

/*
 * Author walkthrough fixes (book 733, "The Kite Over Jacmel"): a novel
 * written through the browser only, on the fake editorial driver.
 */

use App\Models\Book;
use App\Models\User;
use App\Publishing\Anatomy;

it('keeps the author on the title page when no house name is printed there', function () {
    config(['app.name' => 'Écrivain']);
    $novel = Book::factory()->for(User::factory())->create(['author_name' => 'Écrivain', 'series_name' => null, 'publication' => []]);
    $titlePage = collect(Anatomy::plan($novel)['pages'])->firstWhere('part', 'title_page');

    expect(Anatomy::titlePageAuthor($novel))->toBe('Écrivain')
        ->and($titlePage['data']['author'])->toBe('Écrivain');
});

it('still names no author when the same house is printed on the title page', function () {
    $prepa = Book::factory()->make(['author_name' => 'Écrivain', 'series_name' => 'Écrivain Prépa', 'publication' => []]);
    $imprint = Book::factory()->make(['author_name' => 'Maison Lumière', 'publication' => ['imprint' => 'Maison Lumière']]);

    expect(Anatomy::titlePageAuthor($prepa))->toBeNull()
        ->and(Anatomy::titlePageAuthor($imprint))->toBeNull()
        ->and(Anatomy::titlePageAuthor(Book::factory()->make(['author_name' => '', 'series_name' => 'Écrivain Prépa'])))->toBeNull();
});
