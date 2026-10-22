<?php

namespace App\Journey;

use App\Models\Book;
use App\Models\ManuscriptSection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a book's skeleton from its type and the scale the author chose.
 *
 * The output is what the demo book looks like: front matter, parts holding
 * numbered chapters, back matter. Empty chapters with a prompt rather than
 * invented prose — the scaffold is a place to write, and filling it with
 * generated text would be writing the author's book for them before they
 * have said anything about it.
 */
class Scaffolder
{
    /**
     * @param  int|null  $chapters  how many, or the type's suggestion
     * @param  bool  $useParts  group chapters under parts
     */
    public function build(
        Book $book,
        BookType $type,
        ?int $chapters = null,
        ?bool $useParts = null,
    ): Book {
        $chapters = max(1, min(60, $chapters ?? $type->suggestedChapters()));
        $useParts = $useParts ?? $type->usesParts();

        // One transaction: a half-built scaffold is worse than none, because
        // the author cannot tell which chapters are theirs.
        DB::transaction(function () use ($book, $type, $chapters, $useParts) {
            $position = -(count($type->frontMatter()) + 1) * ManuscriptSection::POSITION_STEP;

            foreach ($type->frontMatter() as $title) {
                $this->matter($book, 'front_matter', $title, $position);
                $position += ManuscriptSection::POSITION_STEP;
            }

            $useParts
                ? $this->chaptersInParts($book, $type, $chapters)
                : $this->flatChapters($book, $type, $chapters);

            $position = 970;

            foreach ($type->backMatter() as $title) {
                $this->matter($book, 'back_matter', $title, $position);
                $position += ManuscriptSection::POSITION_STEP;
            }

            // The chapters carry their prompts' word counts; the book's
            // total is denormalised and read by the shelf and Overview.
            $book->recalculateWordCount();
        });

        return $book->refresh();
    }

    /**
     * Chapters grouped under parts.
     *
     * Three parts unless there are too few chapters to divide — a part
     * holding one chapter is an empty gesture. The parts are named
     * generically because only the author knows what divides their book;
     * naming them "Beginning, Middle, End" would be a guess wearing a
     * confident face.
     */
    private function chaptersInParts(Book $book, BookType $type, int $chapters): void
    {
        $partCount = match (true) {
            $chapters < 6 => 1,
            $chapters < 15 => 2,
            $chapters < 25 => 3,
            default => 4,
        };

        $perPart = (int) ceil($chapters / $partCount);
        $number = 0;

        for ($index = 1; $index <= $partCount; $index++) {
            $part = $book->sections()->create([
                'type' => 'part',
                'title' => 'Part '.$this->roman($index),
                // Between the chapters it holds, which are numbered from 1.
                'position' => ($index * $perPart * ManuscriptSection::POSITION_STEP)
                    - ($perPart * ManuscriptSection::POSITION_STEP)
                    + 1,
                'content' => $this->emptyDoc(),
            ]);

            for ($inPart = 0; $inPart < $perPart && $number < $chapters; $inPart++) {
                $this->chapter($book, $type, ++$number, $part);
            }
        }
    }

    private function flatChapters(Book $book, BookType $type, int $chapters): void
    {
        for ($number = 1; $number <= $chapters; $number++) {
            $this->chapter($book, $type, $number);
        }
    }

    private function chapter(
        Book $book,
        BookType $type,
        int $number,
        ?ManuscriptSection $part = null,
    ): void {
        $section = $book->sections()->create([
            'type' => 'chapter',
            'title' => "Chapter {$number}",
            'position' => $number * ManuscriptSection::POSITION_STEP,
            // The prompt is the only content. It is a question about the
            // author's own book rather than prose pretending to be theirs,
            // and it is deleted the moment they start typing over it.
            'content' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $type->chapterPrompt()]],
                ]],
            ],
        ]);

        // forceFill for parent_id: it is deliberately not mass-assignable,
        // because where a section sits in the tree is structure the server
        // decides rather than something a request may claim. Passing it to
        // create() is silently dropped, which is how every chapter ended up
        // beside its parts instead of inside them.
        $section->forceFill([
            'parent_id' => $part?->id,
            'word_count' => ManuscriptSection::countWords($section->content),
        ])->save();
    }

    private function matter(Book $book, string $type, string $title, int $position): void
    {
        $book->sections()->create([
            'type' => $type,
            'title' => $title,
            'position' => $position,
            'content' => $this->emptyDoc(),
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyDoc(): array
    {
        return ['type' => 'doc', 'content' => []];
    }

    private function roman(int $number): string
    {
        return ['', 'I', 'II', 'III', 'IV', 'V', 'VI'][$number] ?? (string) $number;
    }
}
