<?php

use App\Editorial\Style\HouseGuideTemplate;
use App\Editorial\Style\StyleGuides;
use App\Editorial\Style\StyleRules;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\StyleGuide;
use App\Models\StyleGuideVersion;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use Illuminate\Support\Facades\DB;

/**
 * Style guides (ADR — Style guides and review options): the schema and its
 * bounds, immutable versions, explicit follow and detach, the book's own
 * rules, and a guide that never leaves the account that owns it.
 */
function sgBook(User $owner, string $title = 'Livre', string $text = 'Le texte du livre.'): Book
{
    $book = Book::factory()->for($owner)->create(['title' => $title]);
    $book->sections()->create(['type' => 'chapter', 'title' => 'Un', 'position' => 10, 'content' => platformDoc($text), 'word_count' => 4]);

    return $book->fresh();
}

const SG_RULES = [
    'language' => 'fr-FR',
    'preferred_terms' => [['use' => 'courriel', 'avoid' => 'e-mail', 'note' => 'OQLF.']],
    'banned' => [['phrase' => 'au jour d’aujourd’hui']],
    'dictionary' => ['Kreyòl', 'lakou'],
    'numbers' => ['spell_out_below' => 10, 'numerals_for_measurements' => true],
    'serial_comma' => false,
    'quotes' => 'guillemets',
    'french_spacing' => true,
    'dashes' => 'en_spaced',
    'heading_case' => 'sentence',
    'date_format' => 'dmy',
    'notes' => 'Tutoyer le lecteur.',
];

it('creates a guide the book follows, version 1, with every field stored', function () {
    $author = User::factory()->create();
    $book = sgBook($author);

    $this->actingAs($author)->postJson("/books/{$book->id}/style-guide", ['name' => 'Maison', 'rules' => SG_RULES])
        ->assertCreated()
        ->assertJsonPath('style.following.name', 'Maison')
        ->assertJsonPath('style.following.version', 1)
        ->assertJsonPath('style.rules.quotes', 'guillemets')
        ->assertJsonPath('style.configured', true);

    $guide = StyleGuide::sole();
    expect($guide->user_id)->toBe($author->id)
        ->and($guide->current->rules['preferred_terms'][0])->toEqual(['use' => 'courriel', 'avoid' => 'e-mail', 'note' => 'OQLF.'])
        ->and($guide->current->rules['numbers'])->toEqual(['spell_out_below' => 10, 'numerals_for_measurements' => true])
        ->and($book->fresh()->style_guide_id)->toBe($guide->id);

    // The same name twice is refused.
    $this->actingAs($author)->postJson("/books/{$book->id}/style-guide", ['name' => 'Maison'])->assertUnprocessable();
});

it('validates the schema and its bounds', function (array $rules, string $field) {
    $author = User::factory()->create();
    $book = sgBook($author);

    $this->actingAs($author)->postJson("/books/{$book->id}/style-guide", ['name' => 'G', 'rules' => $rules])
        ->assertUnprocessable();
    $this->actingAs($author)->postJson("/books/{$book->id}/style-guide", ['name' => 'Good name', 'rules' => $rules])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(StyleGuide::count())->toBe(0);
})->with([
    'language' => [['language' => 'de-DE'], 'language'],
    'quotes' => [['quotes' => 'straight'], 'quotes'],
    'dashes' => [['dashes' => 'hyphen'], 'dashes'],
    'spell out' => [['numbers' => ['spell_out_below' => 12]], 'numbers.spell_out_below'],
    'notes too long' => [['notes' => str_repeat('a', StyleRules::MAX_NOTES + 1)], 'notes'],
    'term without use' => [['preferred_terms' => [['avoid' => 'x']]], 'preferred_terms.0.use'],
    'too many terms' => [['banned' => array_fill(0, StyleRules::MAX_TERMS + 1, ['phrase' => 'x'])], 'banned'],
    'dictionary word too long' => [['dictionary' => [str_repeat('a', 61)]], 'dictionary.0'],
    'heading case' => [['heading_case' => 'camel'], 'heading_case'],
    'date format' => [['date_format' => 'ymd'], 'date_format'],
]);

it('normalises: trims, deduplicates and drops unknown keys', function () {
    $rules = StyleRules::validate([
        'dictionary' => [' Kreyòl ', 'kreyòl', '', 'lakou'],
        'preferred_terms' => [['use' => 'a', 'avoid' => 'b'], ['use' => 'c', 'avoid' => 'B'], ['use' => 'same', 'avoid' => 'Same']],
        'banned' => [['phrase' => '  x  '], ['phrase' => 'X']],
        'evil' => 'ignored',
    ]);

    expect($rules['dictionary'])->toBe(['Kreyòl', 'lakou'])
        ->and($rules['preferred_terms'])->toBe([['use' => 'a', 'avoid' => 'b', 'note' => null]])
        ->and($rules['banned'])->toBe([['phrase' => 'x', 'note' => null]])
        ->and($rules)->not->toHaveKey('evil')
        ->and(StyleRules::isEmpty(StyleRules::validate([])))->toBeTrue();
});

it('saves each change as a new immutable version; an unchanged save is not a version', function () {
    $author = User::factory()->create();
    $book = sgBook($author);
    $guide = app(StyleGuides::class)->create($author, $author, 'Maison', SG_RULES);
    $first = $guide->current;

    $this->actingAs($author)->putJson("/style-guides/{$guide->id}", ['rules' => array_merge(SG_RULES, ['serial_comma' => true]), 'book' => $book->id])
        ->assertOk()->assertJsonPath('guide.version', 2);
    $this->actingAs($author)->putJson("/style-guides/{$guide->id}", ['rules' => array_merge(SG_RULES, ['serial_comma' => true])])
        ->assertOk()->assertJsonPath('guide.version', 2);

    expect(StyleGuideVersion::where('style_guide_id', $guide->id)->count())->toBe(2)
        ->and($first->fresh()->rules['serial_comma'])->toBeFalse()
        ->and($guide->fresh()->current->rules['serial_comma'])->toBeTrue();

    // Neither the model nor the database lets a version change.
    expect(fn () => $first->fresh()->update(['rules' => []]))->toThrow(LogicException::class);
    expect(fn () => DB::table('style_guide_versions')->where('id', $first->id)->update(['rules' => '{}']))->toThrow(Illuminate\Database\QueryException::class);
});

it('follows explicitly, and "Customise for this book" keeps the rules as the book’s own', function () {
    $author = User::factory()->create();
    $book = sgBook($author);
    $other = sgBook($author, 'Autre');
    $guide = app(StyleGuides::class)->create($author, $author, 'Maison', SG_RULES);

    // Creating a guide from one book does not make another follow it.
    expect($other->fresh()->style_guide_id)->toBeNull();

    $this->actingAs($author)->postJson("/books/{$book->id}/style-guide/follow", ['guide' => $guide->id])
        ->assertOk()->assertJsonPath('style.following.id', $guide->id);

    // The book's own words beside the guide.
    $this->actingAs($author)->putJson("/books/{$book->id}/style-guide/own", ['rules' => ['dictionary' => ['Ti Jan'], 'serial_comma' => true]])
        ->assertOk()
        ->assertJsonPath('style.rules.serial_comma', true)
        ->assertJsonPath('style.rules.dictionary', ['Kreyòl', 'lakou', 'Ti Jan']);

    $this->actingAs($author)->postJson("/books/{$book->id}/style-guide/detach")
        ->assertOk()->assertJsonPath('style.following', null)
        ->assertJsonPath('style.rules.quotes', 'guillemets')
        ->assertJsonPath('style.rules.serial_comma', true);

    // A later change to the guide no longer reaches the detached book.
    app(StyleGuides::class)->save($guide, $author, array_merge(SG_RULES, ['quotes' => 'double']));
    expect(app(StyleGuides::class)->effective($book->fresh())['rules']['quotes'])->toBe('guillemets');
});

it('reads the latest version, and deleting a guide leaves its rules with the books', function () {
    $author = User::factory()->create();
    $book = sgBook($author);
    $guides = app(StyleGuides::class);
    $guide = $guides->create($author, $author, 'Maison', SG_RULES);
    $guides->follow($guide, $book, $author);
    $guides->save($guide, $author, array_merge(SG_RULES, ['dashes' => 'em_closed']));

    $effective = $guides->effective($book->fresh());
    expect($effective['version']->version)->toBe(2)->and($effective['rules']['dashes'])->toBe('em_closed');

    $this->actingAs($author)->deleteJson("/style-guides/{$guide->id}")->assertOk();
    $book->refresh();
    expect($book->style_guide_id)->toBeNull()
        ->and($guides->effective($book)['rules']['dashes'])->toBe('em_closed');
});

it('never lets a guide cross accounts', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceBook = sgBook($alice);
    $bobBook = sgBook($bob);
    $guide = app(StyleGuides::class)->create($alice, $alice, 'Alice house', SG_RULES);

    // Bob cannot follow, edit, delete or see Alice's guide.
    $this->actingAs($bob)->postJson("/books/{$bobBook->id}/style-guide/follow", ['guide' => $guide->id])->assertForbidden();
    $this->actingAs($bob)->putJson("/style-guides/{$guide->id}", ['rules' => []])->assertForbidden();
    $this->actingAs($bob)->deleteJson("/style-guides/{$guide->id}")->assertForbidden();
    $this->actingAs($bob)->getJson("/books/{$bobBook->id}/style-guide")->assertOk()->assertJsonPath('style.guides', []);
    $this->actingAs($bob)->getJson("/books/{$aliceBook->id}/style-guide")->assertForbidden();

    // Alice cannot attach her guide to Bob's book either.
    $this->actingAs($alice)->postJson("/books/{$bobBook->id}/style-guide/follow", ['guide' => $guide->id])->assertForbidden();

    // A book moved to another account stops reading its old owner's guide.
    app(StyleGuides::class)->follow($guide, $aliceBook, $alice);
    $aliceBook->forceFill(['user_id' => $bob->id])->save();
    expect(app(StyleGuides::class)->effective($aliceBook->fresh())['version'])->toBeNull();
});

it('lets an editor change the book’s own rules but not the account’s guides; a reviewer neither', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $reviewer = User::factory()->create();
    $book = sgBook($owner);
    $book->members()->create(['user_id' => $editor->id, 'email' => $editor->email, 'role' => 'editor', 'status' => 'active']);
    $book->members()->create(['user_id' => $reviewer->id, 'email' => $reviewer->email, 'role' => 'reviewer', 'status' => 'active']);
    $guide = app(StyleGuides::class)->create($owner, $owner, 'Maison', SG_RULES);

    $this->actingAs($editor)->putJson("/books/{$book->id}/style-guide/own", ['rules' => ['dictionary' => ['Ti Jan']]])->assertOk();
    $this->actingAs($editor)->getJson("/books/{$book->id}/style-guide")->assertOk()->assertJsonPath('style.can_manage', false)->assertJsonPath('style.guides', []);
    $this->actingAs($editor)->postJson("/books/{$book->id}/style-guide/follow", ['guide' => $guide->id])->assertForbidden();
    $this->actingAs($editor)->postJson("/books/{$book->id}/style-guide", ['name' => 'Mine'])->assertForbidden();

    $this->actingAs($reviewer)->putJson("/books/{$book->id}/style-guide/own", ['rules' => ['dictionary' => ['x']]])->assertForbidden();
    $this->actingAs($reviewer)->getJson("/books/{$book->id}/style-guide")->assertOk()->assertJsonPath('style.can_edit', false);
});

it('lets the platform owner keep a house account’s guide, and not support', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $book = sgBook($house, 'Prépa');
    $owner = platformAdmin();
    $support = platformAdmin('support');

    $this->actingAs($owner)->postJson("/books/{$book->id}/style-guide", ['name' => 'Prépa', 'rules' => SG_RULES])->assertCreated();
    expect(StyleGuide::sole()->user_id)->toBe($house->id);

    $this->actingAs($support)->putJson('/style-guides/'.StyleGuide::sole()->id, ['rules' => []])->assertForbidden();
});

it('previews and creates the Écrivain Prépa guide from the console, with Kreyòl glossary words, audited', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $book = Book::factory()->for($house)->create(['title' => 'Kreyòl 9e AF', 'language' => 'ht']);
    $book->sections()->create(['type' => 'back_matter', 'title' => 'Leksik ak rapèl', 'position' => 90, 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'detèminan', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' — Mo ki akonpaye yon non.'],
        ]],
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'chan leksikal', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' — Tout mo ki gen rapò ak menm tèm.'],
        ]],
        // Not a term: a whole bold sentence with punctuation and digits.
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Tablo 3: 12 egzanp.', 'marks' => [['type' => 'bold']]]]],
    ]]]);
    $french = Book::factory()->for($house)->create(['title' => 'Français', 'language' => 'fr']);
    $french->sections()->create(['type' => 'back_matter', 'title' => 'Lexique', 'position' => 90, 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'métaphore', 'marks' => [['type' => 'bold']]]]],
    ]]]);
    $before = json_encode($book->sections()->first()->content);
    $owner = platformAdmin();

    $preview = $this->actingAs($owner)->postJson('/admin/house/style-guide/preview', ['house' => 'ecrivain-prepa'])->assertOk()->json();
    expect($preview['rules']['dictionary'])->toContain('detèminan', 'chan leksikal', 'Kreyòl')
        ->not->toContain('métaphore', 'Tablo 3: 12 egzanp.')
        ->and($preview['rules']['language'])->toBe('fr-FR')
        ->and($preview['rules']['quotes'])->toBe('guillemets')
        ->and($preview['sources'])->toHaveCount(1)
        ->and(StyleGuide::count())->toBe(0);

    $this->actingAs($owner)->post('/admin/house/style-guide', ['house' => 'ecrivain-prepa', 'reason' => 'Series style'])->assertRedirect();
    $guide = StyleGuide::sole();
    expect($guide->user_id)->toBe($house->id)
        ->and($guide->name)->toBe(HouseGuideTemplate::NAME)
        ->and(AdminAuditLog::where('action', 'house.style_guide_created')->where('reason', 'Series style')->exists())->toBeTrue()
        // Read only: the glossary is untouched.
        ->and(json_encode($book->sections()->first()->fresh()->content))->toBe($before);

    // Once only; support may not; an author's account is not a house.
    $this->actingAs($owner)->post('/admin/house/style-guide', ['house' => 'ecrivain-prepa', 'reason' => 'Again'])->assertSessionHasErrors('house');
    $this->actingAs(platformAdmin('support'))->post('/admin/house/style-guide', ['house' => 'ecrivain-prepa', 'reason' => 'Nope'])->assertForbidden();
    expect(StyleGuide::count())->toBe(1);
});
