<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;
use App\Models\User;

it('adds a source to the library as unverified', function () {
    // Arriving pre-approved would make verification meaningless.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/sources", [
            'kind' => 'journal_article',
            'title' => 'Practice Economics',
            'authors' => 'Smith, J.',
            'published' => '2019',
        ])
        ->assertCreated()
        ->assertJsonPath('sources.0.verification', 'unverified')
        ->assertJsonPath('sources.0.usable_as_evidence', false)
        ->assertJsonPath('sources.0.reference', 'Smith, J. (2019)');
});

it('records who verified a source and when', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $source = ResearchSource::factory()->for($book)->create();

    $this->actingAs($user)
        ->patchJson("/sources/{$source->id}/verification", ['verification' => 'verified'])
        ->assertOk()
        ->assertJsonPath('sources.0.usable_as_evidence', true);

    $source->refresh();

    expect($source->verified_by)->toBe($user->id)
        ->and($source->verified_at)->not->toBeNull();
});

it('refuses to let one author verify another author\'s source', function () {
    $source = ResearchSource::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create();

    $this->actingAs(User::factory()->create())
        ->patchJson("/sources/{$source->id}/verification", ['verification' => 'verified'])
        ->assertForbidden();
});

it('rejects a supported claim with no source, with a usable message', function () {
    // The database would refuse this anyway; the controller mirrors the
    // constraint so the author gets a 422 instead of a 500.
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create();

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/citations", [
            'claim' => 'A claim.',
            'status' => 'supported',
        ])
        ->assertStatus(422);
});

it('refuses to cite a source belonging to a different book', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create();

    $foreign = ResearchSource::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create();

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/citations", [
            'claim' => 'A claim.',
            'status' => 'supported',
            'research_source_id' => $foreign->id,
        ])
        ->assertStatus(422);
});

it('shows a citation resting on an unverified source as exactly that', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();
    $source = ResearchSource::factory()->for($book)->create();

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/citations", [
            'claim' => 'A claim.',
            'status' => 'supported',
            'research_source_id' => $source->id,
        ])
        ->assertCreated()
        ->assertJsonPath('citations.0.rests_on_unverified_source', true);
});

it('leaves claims visibly unsupported when their source is removed', function () {
    // A bibliography that quietly looks complete after a source is deleted
    // is the failure mode worth preventing.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();
    $source = ResearchSource::factory()->for($book)->verified()->create();

    $this->actingAs($user)->postJson("/sections/{$section->id}/citations", [
        'claim' => 'A claim.',
        'status' => 'supported',
        'research_source_id' => $source->id,
    ])->assertCreated();

    $this->actingAs($user)->deleteJson("/sources/{$source->id}")->assertOk();

    $this->actingAs($user)
        ->getJson("/sections/{$section->id}/citations")
        ->assertOk()
        ->assertJsonPath('citations.0.status', 'needs_source')
        ->assertJsonPath('citations.0.source', null);
});

it('reports cross-chapter inconsistencies over HTTP', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete();
    $position = 0;

    foreach (['Chapter 1' => 'John arrived.', 'Chapter 2' => 'Jon left.'] as $title => $text) {
        $position += ManuscriptSection::POSITION_STEP;
        $book->sections()->create([
            'type' => 'chapter',
            'title' => $title,
            'position' => $position,
            'content' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]]],
        ]);
    }

    $this->actingAs($user)->postJson("/books/{$book->id}/terms", [
        'kind' => 'character',
        'canonical' => 'John',
        'variants' => ['Jon'],
    ])->assertCreated();

    $this->actingAs($user)
        ->getJson("/books/{$book->id}/consistency")
        ->assertOk()
        ->assertJsonPath('issues.0.variant', 'Jon')
        ->assertJsonPath('issues.0.sections.0.section_title', 'Chapter 2');
});

it('drops a variant identical to the canonical spelling', function () {
    // Keeping it would flag correct text forever.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/terms", [
            'kind' => 'term',
            'canonical' => 'John',
            'variants' => ['John', 'Jon'],
        ])
        ->assertCreated()
        ->assertJsonPath('terms.0.variants', ['Jon']);
});

it('imports a manuscript over HTTP and reports what it skipped', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", [
            'markdown' => "# One\n\nBody text here.\n\n![pic](a.png)",
        ])
        ->assertCreated()
        ->assertJsonPath('report.sections', 1)
        ->assertJsonPath('report.skipped.image', 1)
        ->assertJsonPath('report.lossless', false);
});

it('refuses to import into a book you do not own', function () {
    $book = Book::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/books/{$book->id}/import", ['markdown' => '# One'])
        ->assertForbidden();
});

it('rejects an empty import rather than creating nothing silently', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/import", ['markdown' => "   \n  "])
        ->assertStatus(422);
});

it('exports the manuscript as a Markdown download', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['title' => 'My Book']);

    $this->actingAs($user)
        ->get("/books/{$book->id}/export.md")
        ->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8');
});
