<?php

namespace App\Ai;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Accepting a plan builds the binder from it (ADR — AI writes the book).
 *
 * Like importing a structured outline: untouched chapters (no words, a
 * scaffold prompt at most) and the parts left empty go; anything the
 * author wrote stays where it is, and a plan entry with the same title as
 * a written section is tied to it rather than duplicated. Front and back
 * matter of the same type and title are reused. Every new section starts
 * as an empty page, so the AI's first save leaves the `ai_draft` version
 * the disclosure counts.
 *
 * The book takes the chosen title only while it still carries the idea
 * Quick Start stored as a placeholder; the subtitle, reader and promise
 * fill only fields that are empty. Fiction's characters are declared in
 * Characters & Terms, by the author accepting them.
 */
class PlanApplier
{
    /** @return int sections created */
    public function apply(Book $book, array $plan, User $user): int
    {
        return DB::transaction(function () use ($book, $plan) {
            $book = Book::lockForUpdate()->findOrFail($book->id);

            $book->sections()->where('word_count', 0)->where('type', 'chapter')->get()->each->delete();
            $book->sections()->where('type', 'part')->where('word_count', 0)->get()
                ->reject(fn (ManuscriptSection $part) => $part->children()->exists())
                ->each->delete();

            $existing = $book->sections()->get();
            $find = fn (string $type, string $title) => $existing->first(
                fn (ManuscriptSection $s) => $s->type === $type && mb_strtolower(trim($s->title)) === mb_strtolower(trim($title)),
            );

            $created = 0;
            $empty = ['type' => 'doc', 'content' => []];

            // Front matter before everything already in the binder.
            $position = (int) $book->sections()->min('position') - (count($plan['front_matter']) + 1) * ManuscriptSection::POSITION_STEP;

            foreach ($plan['front_matter'] as $item) {
                $position += ManuscriptSection::POSITION_STEP;
                $created += $this->place($book, $find('front_matter', $item['title']), 'front_matter', $item, $position, null, $empty);
            }

            $position = max((int) $book->sections()->where('type', '!=', 'back_matter')->max('position'), 0);
            $useParts = count($plan['parts']) > 1;

            foreach ($plan['parts'] as $part) {
                $parent = null;

                if ($useParts) {
                    $position += ManuscriptSection::POSITION_STEP;
                    $parent = $find('part', $part['title']) ?? $book->sections()->create([
                        'type' => 'part',
                        'title' => $part['title'] ?: 'Part',
                        'position' => $position,
                        'content' => $empty,
                    ]);
                    $created += $parent->wasRecentlyCreated ? 1 : 0;
                }

                foreach ($part['chapters'] as $item) {
                    $position += ManuscriptSection::POSITION_STEP;
                    $created += $this->place($book, $find('chapter', $item['title']), 'chapter', $item, $position, $parent, $empty);
                }
            }

            $position = max($position, (int) $book->sections()->max('position'));

            foreach ($plan['back_matter'] as $item) {
                $position += ManuscriptSection::POSITION_STEP;
                $created += $this->place($book, $find('back_matter', $item['title']), 'back_matter', $item, $position, null, $empty);
            }

            $brief = $book->ai_plan['brief'] ?? [];
            $updates = [];
            $placeholder = trim((string) ($brief['idea'] ?? ''));

            if (filled($plan['title'] ?? null) && ($book->title === $placeholder || $book->title === mb_substr($placeholder, 0, 255) || blank($book->title))) {
                $updates['title'] = $plan['title'];
            }

            foreach (['subtitle' => 'subtitle', 'audience' => 'reader', 'promise' => 'promise'] as $field => $key) {
                if (blank($book->{$field}) && filled($plan[$key] ?? null)) {
                    $updates[$field] = mb_substr($plan[$key], 0, $field === 'subtitle' ? 255 : 2000);
                }
            }

            if (blank($book->premise) && $placeholder !== '') {
                $updates['premise'] = mb_substr($placeholder, 0, 2000);
            }

            if (in_array($book->journey_stage, ['discover', 'plan'], true)) {
                $updates['journey_stage'] = 'create';
            }

            $book->fill($updates);
            $book->forceFill(['ai_plan' => array_merge($book->ai_plan ?? [], [
                'status' => 'accepted',
                'plan' => $plan,
                'accepted_at' => now()->toIso8601String(),
            ])])->save();

            foreach ($plan['characters'] ?? [] as $character) {
                if (! $book->terms()->whereRaw('lower(canonical) = ?', [mb_strtolower($character['name'])])->exists()) {
                    $book->terms()->create([
                        'kind' => 'character',
                        'canonical' => $character['name'],
                        'variants' => [],
                        'note' => trim($character['role'].($character['description'] ? ' — '.$character['description'] : '')),
                    ]);
                }
            }

            $book->recalculateWordCount();

            return $created;
        });
    }

    private function place(Book $book, ?ManuscriptSection $section, string $type, array $item, int $position, ?ManuscriptSection $parent, array $empty): int
    {
        $created = 0;

        if (! $section) {
            $section = $book->sections()->create([
                'type' => $type,
                'title' => $item['title'],
                'position' => $position,
                'content' => $empty,
            ]);
            $created = 1;
        }

        // Where a section sits and which plan entry it answers are the
        // server's to decide (ADR-0012): forceFill, never mass assignment.
        $section->forceFill(array_filter([
            'plan_key' => $item['key'],
            'parent_id' => $created && $parent ? $parent->id : null,
        ], fn ($v) => $v !== null))->save();

        return $created;
    }
}
