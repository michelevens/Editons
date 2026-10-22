<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;

/*
 * Reordering chapters from the binder: drag-and-drop and move up/down send
 * the new order of one level, and the server rewrites sparse positions.
 */

function orderedBook(): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $sections = collect(['One', 'Two', 'Three'])->map(fn ($title, $i) => $book->sections()->create([
        'type' => 'chapter', 'title' => $title, 'position' => ($i + 1) * ManuscriptSection::POSITION_STEP,
    ]));

    return [$user, $book, $sections];
}

function binderTitles(Book $book, ?int $parentId = null): array
{
    return $book->sections()->where('parent_id', $parentId)->orderBy('position')->pluck('title')->all();
}

it('moves a chapter and rewrites positions in steps', function () {
    [$user, $book, $s] = orderedBook();

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/sections/order", ['ids' => [$s[2]->id, $s[0]->id, $s[1]->id]])
        ->assertOk()
        ->assertJsonPath('order', [$s[2]->id, $s[0]->id, $s[1]->id]);

    expect(binderTitles($book))->toBe(['Three', 'One', 'Two'])
        ->and($book->sections()->orderBy('position')->pluck('position')->all())
        ->toBe([10, 20, 30]);
});

it('reorders the chapters inside a part', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $part = $book->sections()->create(['type' => 'part', 'title' => 'Part I', 'position' => 10]);
    $a = $book->sections()->create(['type' => 'chapter', 'title' => 'A', 'position' => 10]);
    $b = $book->sections()->create(['type' => 'chapter', 'title' => 'B', 'position' => 20]);
    $a->forceFill(['parent_id' => $part->id])->save();
    $b->forceFill(['parent_id' => $part->id])->save();

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/sections/order", ['parent_id' => $part->id, 'ids' => [$b->id, $a->id]])
        ->assertOk();

    expect(binderTitles($book, $part->id))->toBe(['B', 'A']);
});

it('refuses a list that does not name exactly the siblings there now', function () {
    [$user, $book, $s] = orderedBook();
    $url = "/books/{$book->id}/sections/order";

    // One left out: a stale binder from another tab.
    $this->actingAs($user)->patchJson($url, ['ids' => [$s[1]->id, $s[0]->id]])->assertStatus(409);

    // Another book's chapter slipped in.
    $other = Book::factory()->create()->sections()->create(['type' => 'chapter', 'title' => 'X', 'position' => 10]);
    $this->actingAs($user)->patchJson($url, ['ids' => [$s[0]->id, $s[1]->id, $s[2]->id, $other->id]])->assertStatus(409);

    // Duplicates.
    $this->actingAs($user)->patchJson($url, ['ids' => [$s[0]->id, $s[0]->id, $s[1]->id]])->assertStatus(422);

    expect(binderTitles($book))->toBe(['One', 'Two', 'Three']);
});

it('refuses a parent from another book and strangers', function () {
    [$user, $book, $s] = orderedBook();
    $foreignPart = Book::factory()->create()->sections()->create(['type' => 'part', 'title' => 'P', 'position' => 10]);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/sections/order", ['parent_id' => $foreignPart->id, 'ids' => [$s[0]->id]])
        ->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->patchJson("/books/{$book->id}/sections/order", ['ids' => [$s[2]->id, $s[1]->id, $s[0]->id]])
        ->assertForbidden();

    expect(binderTitles($book))->toBe(['One', 'Two', 'Three']);
});

it('adds a chapter inside the part it was asked for', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $part = $book->sections()->create(['type' => 'part', 'title' => 'Part I', 'position' => 10]);

    $this->actingAs($user)
        ->post("/books/{$book->id}/sections", ['type' => 'chapter', 'title' => 'Inside', 'parent_id' => $part->id])
        ->assertRedirect();

    expect($book->sections()->where('title', 'Inside')->value('parent_id'))->toBe($part->id);
});
