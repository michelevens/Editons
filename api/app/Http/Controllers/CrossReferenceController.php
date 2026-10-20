<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Publishing\Numbering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the editor needs to number its chapter and to offer cross-references:
 * the open chapter's prefix and offsets, and every target elsewhere.
 *
 * Targets in other chapters that have no label yet are given one here, so
 * they can be referred to. Only closed chapters are touched: the open one
 * labels its own targets in the browser, and writing to it from here would
 * be overwritten by its next autosave.
 */
class CrossReferenceController extends Controller
{
    public function index(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $current = $request->integer('section') ?: null;
        $canEdit = $request->user()->can('update', $book);

        // The same sections the exports number from: everything with text,
        // plus the chapter being written, which may still be empty.
        $sections = $book->sections()->orderBy('position')->get()
            ->filter(fn (ManuscriptSection $s) => $s->type === 'part' || $s->id === $current || trim($s->plainText()) !== '')
            ->values();

        if ($canEdit) {
            foreach ($sections as $section) {
                if ($section->id !== $current) {
                    $this->label($section);
                }
            }
        }

        $numbering = Numbering::forBook($sections);
        $titles = $sections->pluck('title', 'id');

        return response()->json([
            'scheme' => $numbering['scheme'],
            'section' => $numbering['sections'][$current] ?? ['prefix' => '', 'offsets' => ['table' => 0, 'figure' => 0, 'equation' => 0]],
            'targets' => collect($numbering['targets'])
                ->reject(fn ($t) => $t['section_id'] === $current && $t['kind'] !== 'chapter')
                ->map(fn ($t, $label) => $t + ['label' => $label, 'section_title' => $titles[$t['section_id']] ?? ''])
                ->values(),
        ]);
    }

    /** Gives every caption, numbered equation and heading a label. */
    private function label(ManuscriptSection $section): void
    {
        $content = $section->content;
        $changed = false;

        foreach ($content['content'] ?? [] as $i => $node) {
            if (! empty($node['attrs']['label'])) {
                continue;
            }

            $kind = match (true) {
                (bool) ($caption = Numbering::captionKind($node)) => strtolower($caption[0]),
                ($node['type'] ?? '') === 'blockMath' && str_contains((string) ($node['attrs']['latex'] ?? ''), '\tag{') => 'equation',
                ($node['type'] ?? '') === 'heading' => 'section',
                default => null,
            };

            if ($kind) {
                $content['content'][$i]['attrs']['label'] = Numbering::newLabel($kind);
                $changed = true;
            }
        }

        if ($changed) {
            // Labels are not words: no new version, no change to the text.
            $section->forceFill(['content' => $content])->saveQuietly();
        }
    }
}
