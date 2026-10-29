<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Publishing\Numbering;
use App\Publishing\Placement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the editor needs to number its chapter and to offer cross-references:
 * the open chapter's prefix and offsets, and every target elsewhere.
 *
 * A read and nothing else. It used to label targets in the other chapters
 * and write them back from the copy it had loaded, so an autosave landing
 * between its read and its write was silently undone. Targets are now
 * labelled when content is saved (ManuscriptWriter::save).
 *
 * The editor asks on every chapter it opens, and the answer needs the whole
 * book read and numbered: 1.7 s on a 150,000-word book. So the answer is
 * cached per book, keyed by a fingerprint of every section's order, title
 * and content, which the database computes without sending the content.
 */
class CrossReferenceController extends Controller
{
    /** Long enough to cover a writing session; a stale answer is never served. */
    private const CACHE_SECONDS = 86_400;

    private const NO_OFFSETS = ['table' => 0, 'figure' => 0, 'equation' => 0];

    public function index(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $current = $request->integer('section') ?: null;
        $place = $this->place($book, $current);

        return response()->json([
            'scheme' => $place['scheme'],
            // The scheme chosen in Design, or null when it is inferred.
            'numbering' => $book->layout()['numbering'],
            // The text block in inches [width, height], for the editor's
            // warning on pictures that will print below 300 dpi.
            'text_block' => Placement::textBlock($book->layout()),
            // The scheme as the rest of the book sets it. The open chapter's
            // own captions are on screen, newer than anything saved, and
            // the editor adds them itself (CrossRef.jsx numbersByChapter).
            'scheme_elsewhere' => collect($place['by_chapter'])->except([$current])->contains(true) ? 'chapter' : 'continuous',
            'section' => $place['sections'][$current] ?? ['prefix' => '', 'offsets' => self::NO_OFFSETS, 'running' => self::NO_OFFSETS],
            'targets' => collect($place['targets'])
                ->reject(fn ($t) => $t['section_id'] === $current && $t['kind'] !== 'chapter')
                ->values(),
        ]);
    }

    /**
     * The book's numbering, from the cache when nothing in the book has
     * changed since it was worked out.
     *
     * One entry per book, overwritten when the fingerprint moves, rather
     * than one per fingerprint: every save makes a new fingerprint, and a
     * key per fingerprint would leave a dead copy of the book in the cache
     * store for each.
     */
    private function place(Book $book, ?int $current): array
    {
        $fingerprint = md5($book->sections()->orderBy('position')->toBase()
            ->get(['id', 'type', 'title', 'position', DB::raw('md5(content::text) as digest')])
            ->toJson().'|'.($book->layout()['numbering'] ?? 'auto'));

        $base = $this->cached("cross-references:{$book->id}", $fingerprint, fn () => $this->compute($book, null));

        // The same sections the exports number from: everything with text.
        // A chapter being written may still be empty, and is numbered as if
        // it were not; that differs from the book's answer, so it has its
        // own entry.
        if ($current === null || ! in_array($current, $base['ids'], true) || in_array($current, $base['with_text'], true)) {
            return $base;
        }

        return $this->cached("cross-references:{$book->id}:empty", "{$fingerprint}:{$current}", fn () => $this->compute($book, $current));
    }

    private function cached(string $key, string $fingerprint, callable $compute): array
    {
        $entry = Cache::get($key);

        if (is_array($entry) && ($entry['fingerprint'] ?? null) === $fingerprint) {
            return $entry['place'];
        }

        $place = $compute();
        Cache::put($key, ['fingerprint' => $fingerprint, 'place' => $place], self::CACHE_SECONDS);

        return $place;
    }

    /** Numbers the book, counting $include among the sections with text. */
    private function compute(Book $book, ?int $include): array
    {
        $all = $book->sections()->orderBy('position')->get();
        $withText = $all->filter(fn (ManuscriptSection $s) => trim($s->plainText()) !== '')->pluck('id')->all();

        $sections = $all
            ->filter(fn (ManuscriptSection $s) => $s->type === 'part' || $s->id === $include || in_array($s->id, $withText, true))
            ->values();

        $numbering = Numbering::forBook($sections, $book->layout()['numbering']);
        $titles = $sections->pluck('title', 'id');

        return [
            'ids' => $all->pluck('id')->all(),
            'with_text' => $withText,
            'scheme' => $numbering['scheme'],
            'by_chapter' => $sections->mapWithKeys(fn (ManuscriptSection $s) => [$s->id => Numbering::numbersByChapter($s->content)])->all(),
            'sections' => $numbering['sections'],
            'targets' => collect($numbering['targets'])
                ->map(fn ($t, $label) => $t + ['label' => $label, 'section_title' => $titles[$t['section_id']] ?? ''])
                ->values()
                ->all(),
        ];
    }
}
