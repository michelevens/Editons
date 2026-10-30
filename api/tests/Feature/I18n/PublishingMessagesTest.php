<?php

use App\I18n\Locales;
use App\Models\Book;
use App\Models\User;

/*
 * The messages the publishing screens get from the server (export, publish,
 * cover, layout, rights, editions, launch, book parts) are in the reader's
 * language, and the English is what it always was (ADR-0050, part 2).
 */

afterEach(fn () => Locales::flush());

it('has every server message the publishing controllers name in all three catalogues', function () {
    $controllers = ['Accessibility', 'BookParts', 'CoverTemplate', 'CoverWrap', 'Design', 'Editions', 'Launch', 'PrintKit', 'PublishFlow', 'Publishing', 'Rights'];
    $keys = [];

    foreach ($controllers as $name) {
        preg_match_all("/Locales::t\\('([^']+)'/", file_get_contents(app_path("Http/Controllers/{$name}Controller.php")), $m);
        $keys = [...$keys, ...$m[1]];
    }

    expect($keys)->not->toBeEmpty();

    foreach (['en', 'fr', 'ht'] as $locale) {
        $catalogue = Locales::catalogue($locale);

        expect(array_values(array_diff($keys, array_keys($catalogue))))->toBe([]);
    }
});

it('keeps the English of the publishing messages word for word', function () {
    expect(Locales::t('publish.flow_making', ['count' => 1], 'en'))->toBe('Making 1 file. They appear here when they are ready.')
        ->and(Locales::t('publish.flow_making', ['count' => 3], 'en'))->toBe('Making 3 files. They appear here when they are ready.')
        ->and(Locales::t('export.profile_exists', ['name' => 'Proof'], 'en'))->toBe('This book already has a profile called "Proof".')
        ->and(Locales::t('layout.not_a_setting', ['key' => 'gutter'], 'en'))->toBe('“gutter” is not a layout setting.')
        ->and(Locales::t('rights.rejected_invalid', ['isbn' => '123'], 'en'))->toBe('123 — not a valid ISBN-13');
});

it('refuses an invalid ISBN in the reader’s language', function (string $locale, string $message) {
    $user = User::factory()->create(['locale' => $locale]);
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/isbns", ['edition_format' => 'ebook', 'isbn' => '12345'])
        ->assertStatus(422)
        ->assertJsonPath('errors.isbn.0', $message);
})->with([
    ['en', 'That is not a valid ISBN-13. Check the digits.'],
    ['fr', 'Ce n’est pas un ISBN-13 valide. Vérifiez les chiffres.'],
    ['ht', 'Sa a se pa yon ISBN-13 ki valab. Verifye chif yo.'],
]);

it('lists the rejected numbers of a pool import in the reader’s language', function () {
    $user = User::factory()->create(['locale' => 'ht']);

    $this->actingAs($user)
        ->postJson('/isbns', ['isbns' => ['12345']])
        ->assertOk()
        ->assertJsonPath('added', 0)
        ->assertJsonPath('rejected.0', '12345 — se pa yon ISBN-13 ki valab');
});

it('says an empty book has no edition yet in French', function () {
    // A plan with room for one more book: an edition is a new book.
    $user = User::factory()->subscribed('author')->create(['locale' => 'fr']);
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'large_print'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Écrivez un chapitre avant de créer une édition de ce livre.');
});
