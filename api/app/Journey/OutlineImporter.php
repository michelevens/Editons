<?php

namespace App\Journey;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a list of chapter titles into a binder.
 *
 * Distinct from importing a manuscript, which brings prose with it. An
 * outline is what an author already has in a notes app or on paper — a list,
 * sometimes indented — and retyping it into the binder one chapter at a time
 * is the kind of work that makes somebody give up before they start writing.
 *
 * Indentation means nesting: a line indented under another becomes a chapter
 * under that part. Numbering is stripped, because "1." in their list and the
 * position in the binder are the same fact, and keeping both means they
 * disagree the moment anything moves.
 */
class OutlineImporter
{
    /** Beyond this an outline is a manuscript, and should be imported as one. */
    private const MAX_LINES = 200;

    /**
     * @return int how many sections were created
     *
     * @throws RuntimeException when there is nothing usable in the text
     */
    public function import(Book $book, string $outline): int
    {
        // A proposal's outline — parts, "Chapter 1:" lines, bullets under
        // each — is read for its structure rather than line by line.
        if (StructuredOutline::looksStructured($outline)) {
            return $this->importStructured($book, (new StructuredOutline)->parse($outline));
        }

        $lines = $this->parse($outline);

        if ($lines === []) {
            throw new RuntimeException('There are no chapter titles in that.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new RuntimeException(
                'That is longer than an outline — '.count($lines).' lines. '
                .'Import it as a manuscript instead.',
            );
        }

        return DB::transaction(function () use ($book, $lines) {
            // Replacing, not appending: importing an outline into a book that
            // already has one means the author is correcting it, and ending
            // up with both is never what they meant. Only untouched chapters
            // go — anything with words in it is their work.
            $book->sections()
                ->where('word_count', 0)
                ->whereIn('type', ['chapter', 'part'])
                ->delete();

            $created = 0;
            $position = 0;
            $currentPart = null;

            foreach ($lines as [$depth, $title]) {
                $position += ManuscriptSection::POSITION_STEP;

                // A top-level line with children below it is a part; one
                // without is a chapter. Decided by what follows, because a
                // list does not say which is which.
                $isPart = $depth === 0 && $this->hasChildren($lines, $title);

                $section = $book->sections()->create([
                    'type' => $isPart ? 'part' : 'chapter',
                    'title' => $title,
                    'position' => $position,
                    'content' => ['type' => 'doc', 'content' => []],
                ]);

                if ($isPart) {
                    $currentPart = $section;
                } elseif ($depth > 0 && $currentPart) {
                    // forceFill: where a section sits in the tree is
                    // structure the server decides. See ADR-0012.
                    $section->forceFill(['parent_id' => $currentPart->id])->save();
                }

                $created++;
            }

            return $created;
        });
    }

    /**
     * Build the binder from a structured outline, and keep the rest of it.
     *
     * The chapter plans and everything around the structure go into the
     * book's notes, after whatever the author already had there. Subtitle
     * and audience fill only fields that are empty: the outline does not
     * get to overwrite what the author already decided.
     *
     * @param  array{sections: array, title: ?string, subtitle: ?string, audience: ?string, notes: string}  $parsed
     */
    private function importStructured(Book $book, array $parsed): int
    {
        $chapters = collect($parsed['sections'])->where('type', 'chapter')->count();

        if ($chapters === 0) {
            throw new RuntimeException('There are no chapter titles in that.');
        }

        return DB::transaction(function () use ($book, $parsed) {
            $book->sections()
                ->where('word_count', 0)
                ->whereIn('type', ['chapter', 'part', 'back_matter'])
                ->delete();

            $ids = [];
            $position = 0;

            foreach ($parsed['sections'] as $index => $item) {
                $position += ManuscriptSection::POSITION_STEP;

                $section = $book->sections()->create([
                    'type' => $item['type'],
                    'title' => $item['title'],
                    'subtitle' => $item['subtitle'],
                    'position' => $position,
                    'content' => ['type' => 'doc', 'content' => []],
                ]);

                if ($item['part'] !== null && isset($ids[$item['part']])) {
                    // forceFill: structure is the server's to decide (ADR-0012).
                    $section->forceFill(['parent_id' => $ids[$item['part']]])->save();
                }

                $ids[$index] = $section->id;
            }

            $updates = [];

            if (blank($book->subtitle) && filled($parsed['subtitle'])) {
                $updates['subtitle'] = mb_substr($parsed['subtitle'], 0, 255);
            }

            if (blank($book->audience) && filled($parsed['audience'])) {
                $updates['audience'] = $parsed['audience'];
            }

            if (filled($parsed['notes'])) {
                $updates['notes'] = trim(trim((string) $book->notes).'

'.$parsed['notes']);
            }

            if ($updates !== []) {
                $book->update($updates);
            }

            return count($ids);
        });
    }

    /**
     * Lines as [depth, title].
     *
     * @return array<int, array{0: int, 1: string}>
     */
    private function parse(string $outline): array
    {
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $outline) ?: [] as $raw) {
            if (trim($raw) === '') {
                continue;
            }

            // Depth from leading whitespace. A tab or two spaces is one
            // level, which is what both a notes app and a person typing by
            // hand tend to produce.
            $indent = strlen($raw) - strlen(ltrim($raw, " \t"));
            $depth = $indent > 0 ? 1 : 0;

            $title = $this->clean($raw);

            if ($title !== '') {
                $lines[] = [$depth, $title];
            }
        }

        return $lines;
    }

    /**
     * Strip the list furniture an outline carries.
     *
     * Numbering goes because the binder's position is the same fact, and
     * keeping both means they contradict each other as soon as anything is
     * reordered.
     */
    private function clean(string $line): string
    {
        $title = trim($line);

        // Markdown headings, bullets, and numbering in its usual shapes:
        // "1.", "1)", "Chapter 1:", "Part I —".
        $title = preg_replace('/^#+\s*/', '', $title) ?? $title;
        $title = preg_replace('/^[-*•]\s*/', '', $title) ?? $title;
        $title = preg_replace('/^\d+[.)]\s*/', '', $title) ?? $title;
        $title = preg_replace('/^(chapter|part|section)\s+[\dIVXLC]+\s*[:.\-–—]?\s*/i', '', $title) ?? $title;

        return trim(mb_substr(trim($title), 0, 255));
    }

    /**
     * Whether any line sits beneath this one.
     *
     * @param  array<int, array{0: int, 1: string}>  $lines
     */
    private function hasChildren(array $lines, string $title): bool
    {
        $seen = false;

        foreach ($lines as [$depth, $lineTitle]) {
            if ($seen) {
                // The next line decides it: indented means this was a part,
                // top-level means it was a chapter standing alone.
                return $depth > 0;
            }

            if ($lineTitle === $title && $depth === 0) {
                $seen = true;
            }
        }

        return false;
    }
}
