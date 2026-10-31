<?php

namespace App\Console\Commands;

use App\Platform\BookTransfer;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Move books to any other account (ADR — moving books between any accounts).
 *
 * house:transfer, without the house: the target is a person's account (by id
 * or email) or a house account (by id or slug). A dry run unless --force:
 * it prints what would move and what would stop it, and writes nothing.
 * With --force every book moves in one transaction (all or none), audited
 * per book. A previous owner who is a person becomes an editor of each book
 * unless --drop-previous; a house account is never kept.
 *
 *   php artisan books:transfer 127 401 489 --to=someone@example.com --reason="…"
 *   php artisan books:transfer 199-217 --to=7 --reason="…" --force
 */
class BooksTransfer extends Command
{
    protected $signature = 'books:transfer
        {books* : Book ids, or ranges like 199-217}
        {--to= : The account to move them to: an id, an email address or a house slug}
        {--reason= : Why, for the audit log (required)}
        {--drop-previous : Do not keep the previous owner as an editor}
        {--force : Apply. Without it, nothing is written}';

    protected $description = 'Move books to another account (dry run unless --force)';

    public function handle(BookTransfer $transfer): int
    {
        $ids = HouseTransferBooks::parseIds((array) $this->argument('books'));

        if ($ids === null) {
            $this->error('Give book ids as numbers or ranges, e.g. 199 200 or 199-217.');

            return self::INVALID;
        }

        $to = $transfer->target((string) $this->option('to'));

        if (! $to) {
            $this->error('No account with that id, email address or house slug.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('Give a --reason: it is written to the audit log for every book.');

            return self::INVALID;
        }

        // Null: each book's default, which keeps a person and never a house.
        $keep = $this->option('drop-previous') ? false : null;
        $plan = $transfer->plan($ids, $to, $keep);

        $this->table(['Book', 'Title', 'From', 'Sections', 'Exports', 'Product', 'ISBNs', 'Previous owner', 'Result'], collect($plan['books'])->map(fn ($row) => [
            '#'.$row['id'],
            mb_strimwidth((string) $row['title'], 0, 40, '…'),
            $row['from'] ? '#'.$row['from']['id'] : '—',
            $row['moves']['sections'] ?? '—',
            $row['moves']['exports'] ?? '—',
            isset($row['moves']['product']) ? ($row['moves']['product']['on_sale'] ? 'on sale' : 'draft') : '—',
            $row['moves']['isbns'] ?? '—',
            $row['previous_owner'] ?? '—',
            $row['ok'] ? 'ok' : implode(' ', $row['problems'] ?: $plan['problems']),
        ])->all());

        foreach ($plan['problems'] as $problem) {
            $this->error($problem);
        }

        foreach ($plan['warnings'] as $warning) {
            $this->warn($warning);
        }

        foreach ($plan['books'] as $row) {
            foreach ($row['warnings'] as $warning) {
                $this->warn("#{$row['id']}: {$warning}");
            }
        }

        if (! $plan['ok']) {
            $this->error('Nothing moved: fix the books marked above first.');

            return self::FAILURE;
        }

        $label = $to->isHouse() ? $to->name : "{$to->name} <{$to->email}>";

        if (! $this->option('force')) {
            $this->info('Dry run: nothing was written. '.count($plan['books'])." book(s) would move to \"{$label}\". Add --force to apply.");

            return self::SUCCESS;
        }

        try {
            $transfer->apply($ids, $to, $reason.' (books:transfer, shell)', null, $keep);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info(count($plan['books'])." book(s) moved to \"{$label}\" and audited.");

        return self::SUCCESS;
    }
}
