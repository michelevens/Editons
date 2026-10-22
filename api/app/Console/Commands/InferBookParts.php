<?php

namespace App\Console\Commands;

use App\Models\ManuscriptSection;
use App\Publishing\BookPart;
use Illuminate\Console\Command;

/**
 * Name each section's conventional part (preface, epilogue, index…) from its
 * title. The migration ran this once; this is for books imported since, or
 * to re-check after the heuristics improve.
 *
 * Writes only `part`. Sections the author already named are left alone
 * unless --force is given.
 */
class InferBookParts extends Command
{
    protected $signature = 'books:infer-parts
        {--book= : Only this book id}
        {--force : Re-infer sections that already have a part}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Infer each manuscript section\'s book part from its title';

    public function handle(): int
    {
        $query = ManuscriptSection::query()->with('book:id,language')->orderBy('id');

        if ($this->option('book')) {
            $query->where('book_id', (int) $this->option('book'));
        }

        if (! $this->option('force')) {
            $query->whereNull('part');
        }

        $changed = 0;

        $query->chunkById(500, function ($sections) use (&$changed) {
            foreach ($sections as $section) {
                $part = BookPart::infer($section->title, $section->type, $section->book?->language)?->value;

                if ($part === $section->part) {
                    continue;
                }

                $changed++;
                $this->line(sprintf('#%d  %-40s  %s → %s', $section->id, mb_strimwidth($section->title, 0, 40, '…'), $section->part ?? '—', $part ?? '—'));

                if (! $this->option('dry-run')) {
                    ManuscriptSection::whereKey($section->id)->toBase()->update(['part' => $part]);
                }
            }
        });

        $this->info(($this->option('dry-run') ? 'Would change ' : 'Changed ').$changed.' section(s).');

        return self::SUCCESS;
    }
}
