<?php

use App\Models\Book;
use App\Models\User;
use App\Publishing\RightsDeclaration;

/**
 * The rights declaration offers "Quotations used under fair use / short
 * quotation, credited" (ADR — Safe apply, honest prices, citation fixes):
 * a book of credited short quotations had only "Not sure yet" to choose.
 */
it('offers fair use for credited short quotations, stored and not counted as unsure', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->getJson("/books/{$book->id}/rights")->assertOk()
        ->assertJsonPath('declaration.permissions.fair_use', 'Quotations used under fair use / short quotation, credited');

    $this->actingAs($user)->putJson("/books/{$book->id}/rights/declaration", [
        'holds_rights' => true,
        'materials' => [['kind' => 'quote', 'description' => 'Twenty short quotations, each credited in the notes', 'permission' => 'fair_use']],
    ])->assertOk()->assertJsonPath('declaration.confirmed', true);

    $book->refresh();
    expect($book->rights_declaration['materials'][0]['permission'])->toBe('fair_use')
        ->and(RightsDeclaration::unsure($book))->toBe(0);
});

it('says the options in French and Kreyòl', function (string $locale, string $fairUse, string $unsure) {
    $user = User::factory()->create(['locale' => $locale]);
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->getJson("/books/{$book->id}/rights")->assertOk()
        ->assertJsonPath('declaration.permissions.fair_use', $fairUse)
        ->assertJsonPath('declaration.permissions.unsure', $unsure);
})->with([
    'fr' => ['fr', 'Citations courtes au titre du droit de citation (fair use), créditées', 'Pas encore sûr'],
    'ht' => ['ht', 'Sitasyon kout ki itilize dapre itilizasyon jis (fair use), ak sous yo', 'Poko sèten'],
]);
