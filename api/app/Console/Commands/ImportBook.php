<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Build a book from a JSON manuscript file.
 *
 * Written for importing a long manuscript that was drafted outside the
 * editor, which a seeder cannot do: seeders reset, and this writes somebody's
 * real work into their own account.
 *
 * The JSON carries the whole book — metadata, parts, chapters, and each
 * chapter's blocks — so re-running it replaces the manuscript wholesale
 * rather than appending. That makes drafting iterative: fix a chapter in the
 * file, run it again, and the binder matches.
 *
 * Idempotent on (user, title): the same file run twice leaves one book.
 */
class ImportBook extends Command
{
    protected $signature = 'book:import
        {file? : Path to the JSON manuscript, or omit to read stdin}
        {--email= : The account to import into}
        {--dry-run : Report what would be written without writing it}';

    protected $description = 'Import a manuscript from JSON into an account';

    public function handle(): int
    {
        $path = $this->argument('file');

        /*
         * stdin is the route that works over `railway ssh`.
         *
         * A manuscript is far too large to pass as a shell argument, and the
         * remote shell re-parses anything that is. Piping it in avoids both
         * limits, and keeps the file off the deployed container's disk.
         */
        if ($path === null || $path === '-') {
            $json = stream_get_contents(STDIN);
        } elseif (is_file($path)) {
            $json = file_get_contents($path);
        } else {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['title'], $data['sections'])) {
            $this->error('That file is not a manuscript: it needs a title and sections.');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: ($data['email'] ?? null);
        $user = User::where('email', strtolower((string) $email))->first();

        if (! $user) {
            $this->error("No account for [{$email}].");

            return self::FAILURE;
        }

        [$chapters, $words, $markers] = $this->measure($data['sections']);

        $this->line("<info>{$data['title']}</info>");
        $this->line("  {$chapters} chapters, ".number_format($words).' words');

        // The count that matters most for a book drafted this way: every
        // clinical claim the author still has to check.
        if ($markers > 0) {
            $this->warn("  {$markers} VERIFY markers still to resolve");
        }

        if ($this->option('dry-run')) {
            $this->comment('  Dry run: nothing written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $data) {
            $book = $user->books()->firstOrNew(['title' => $data['title']]);

            $book->fill(array_intersect_key($data, array_flip([
                'subtitle', 'author_name', 'genre', 'book_type', 'description',
                'target_words', 'premise', 'audience', 'promise',
                'journey_stage', 'assistance', 'started_with',
            ])));

            $book->save();

            // Replace rather than append: the JSON is the whole manuscript,
            // so a second run is a correction, not an addition.
            $book->sections()->forceDelete();

            $this->write($book, $data['sections']);

            $book->refresh()->recalculateWordCount();

            $this->info("  Imported as book {$book->id}.");
        });

        return self::SUCCESS;
    }

    /**
     * Write the sections, nesting chapters under the part above them.
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function write(Book $book, array $sections): void
    {
        $position = 0;
        $part = null;

        foreach ($sections as $section) {
            $position += ManuscriptSection::POSITION_STEP;
            $type = $section['type'] ?? 'chapter';

            $row = $book->sections()->create([
                'type' => $type,
                'title' => $section['title'],
                'position' => $position,
                'content' => $this->document($section['blocks'] ?? []),
            ]);

            if ($type === 'part') {
                $part = $row;

                continue;
            }

            // forceFill: where a section sits in the tree is structure the
            // server decides. See ADR-0012.
            if ($type === 'chapter' && $part) {
                $row->forceFill(['parent_id' => $part->id])->save();
            }

            $row->forceFill([
                'word_count' => ManuscriptSection::countWords($row->content),
            ])->save();
        }
    }

    /**
     * ProseMirror document from a list of [kind, text] blocks.
     *
     * @param  array<int, array{0: string, 1: string}>  $blocks
     * @return array<string, mixed>
     */
    private function document(array $blocks): array
    {
        $content = [];

        foreach ($blocks as [$kind, $text]) {
            $content[] = match ($kind) {
                'h2' => $this->heading($text, 2),
                'h3' => $this->heading($text, 3),
                default => [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $text]],
                ],
            };
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function heading(string $text, int $level): array
    {
        return [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /**
     * Chapters, words and outstanding VERIFY markers.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array{0: int, 1: int, 2: int}
     */
    private function measure(array $sections): array
    {
        $chapters = 0;
        $words = 0;
        $markers = 0;

        foreach ($sections as $section) {
            if (($section['type'] ?? 'chapter') === 'chapter') {
                $chapters++;
            }

            foreach ($section['blocks'] ?? [] as [$kind, $text]) {
                $words += str_word_count($text);
                $markers += substr_count($text, '[VERIFY');
            }
        }

        return [$chapters, $words, $markers];
    }
}
