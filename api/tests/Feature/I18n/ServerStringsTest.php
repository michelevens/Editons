<?php

use App\Editorial\EditorialAgent;
use App\I18n\Locales;
use App\Journey\BookType;
use App\Models\Book;
use App\Models\User;

/*
 * Words the server sends ready-made (ADR-0050, part 2): the editorial
 * agents' names, the book types, the overview checklist. English is exactly
 * what it was; French and Kreyòl come from the same catalogue as the
 * screens. The English originals stay where the model or a log reads them.
 */

afterEach(function () {
    app()->setLocale('en');
    Locales::flush();
});

it('keeps every agent name and description in English word for word', function (EditorialAgent $agent) {
    app()->setLocale('en');

    expect($agent->displayLabel())->toBe($agent->label())
        ->and($agent->displayDescription())->toBe($agent->description());
})->with(EditorialAgent::cases());

it('keeps every book type name and blurb in English word for word', function () {
    app()->setLocale('en');

    foreach (BookType::all() as $type) {
        expect($type->displayName())->toBe($type->name())
            ->and($type->displayBlurb())->toBe($type->blurb());
    }
});

it('has a translated name and blurb for every book type and agent', function (string $locale) {
    $catalogue = Locales::catalogue($locale);

    foreach (BookType::all() as $type) {
        expect($catalogue)->toHaveKeys(["booktype.{$type->key}_name", "booktype.{$type->key}_blurb"]);
    }

    foreach (EditorialAgent::cases() as $agent) {
        expect($catalogue)->toHaveKeys(["agent.{$agent->value}_label", "agent.{$agent->value}_description"]);
    }
})->with(['fr', 'ht']);

it('names agents and book types in the reader’s language, but tells the model in English', function () {
    app()->setLocale('fr');

    expect(EditorialAgent::Copy->displayLabel())->toBe('Correcteur')
        ->and(EditorialAgent::Copy->label())->toBe('Copy Editor')
        ->and(EditorialAgent::catalogue()[0]['label'])->toBe('Éditeur structurel')
        ->and(BookType::find('memoir')->toArray()['name'])->toBe('Mémoires')
        ->and(BookType::find('memoir')->name())->toBe('Memoir');

    app()->setLocale('ht');

    expect(EditorialAgent::FactChecker->displayLabel())->toBe('Verifikatè fè')
        ->and(BookType::find('poetry')->displayName())->toBe('Powezi');
});

it('sends the overview checklist in the account’s language', function (?string $locale, string $first, string $detail) {
    $user = User::factory()->create();
    $user->forceFill(['locale' => $locale])->save();
    $book = Book::factory()->for($user)->create();

    $checklist = $this->actingAs($user)->get("/books/{$book->id}/overview")
        ->assertOk()
        ->viewData('page')['props']['checklist'];

    expect($checklist)->toHaveCount(6)
        ->and($checklist[0]['label'])->toBe($first)
        ->and($checklist[3]['detail'])->toBe($detail);
})->with([
    'English' => ['en', 'Draft every chapter', '0 unverified'],
    'French' => ['fr', 'Rédiger chaque chapitre', '0 non vérifiée'],
    'Kreyòl' => ['ht', 'Ekri chak chapit', '0 pa verifye'],
]);

it('lists the book types in French on the new-book form', function () {
    $user = User::factory()->create();
    $user->forceFill(['locale' => 'fr'])->save();

    $types = $this->actingAs($user)->get('/books')
        ->assertOk()
        ->viewData('page')['props']['book_types'] ?? null;

    expect(collect($types)->firstWhere('key', 'fiction')['blurb'] ?? null)->toBe('Un roman ou une novella.');
});
