<?php

namespace App\Console\Commands;

use App\Platform\House\HouseAccounts;
use App\Platform\House\HouseTransfer;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Move books into a house publisher account (ADR — house catalog).
 *
 * A dry run unless --force: it prints what would move and what would stop
 * it, and writes nothing. With --force every book moves in one transaction
 * (all or none), audited per book. The previous owner becomes an editor of
 * each book unless --drop-previous.
 *
 *   php artisan house:transfer 199 200 201 --to=ecrivain-prepa --reason="…"
 *   php artisan house:transfer 199-217 --to=ecrivain-prepa --reason="…" --force
 */
class HouseTransferBooks extends Command
{
    protected $signature = 'house:transfer
        {books* : Book ids, or ranges like 199-217}
        {--to= : The house account, by id or slug}
        {--reason= : Why, for the audit log (required)}
        {--drop-previous : Do not keep the previous owner as an editor}
        {--force : Apply. Without it, nothing is written}';

    protected $description = 'Move books into a house publisher account (dry run unless --force)';

    public function handle(HouseTransfer $transfer, HouseAccounts $accounts): int
    {
        $ids = $this->ids((array) $this->argument('books'));

        if ($ids === null) {
            $this->error('Give book ids as numbers or ranges, e.g. 199 200 or 199-217.');

            return self::INVALID;
        }

        $house = $accounts->find((string) $this->option('to'));

        if (! $house) {
            $this->error('No house account with that id or slug. Create one with php artisan house:create.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('Give a --reason: it is written to the audit log for every book.');

            return self::INVALID;
        }

        $keep = ! $this->option('drop-previous');
        $plan = $transfer->plan($ids, $house, $keep);

        $this->table(['Book', 'Title', 'From', 'Sections', 'Exports', 'Product', 'ISBNs', 'Previous owner', 'Result'], collect($plan['books'])->map(fn ($row) => [
            '#'.$row['id'],
            mb_strimwidth((string) $row['title'], 0, 40, '…'),
            $row['from'] ? '#'.$row['from']['id'] : '—',
            $row['moves']['sections'] ?? '—',
            $row['moves']['exports'] ?? '—',
            isset($row['moves']['product']) ? ($row['moves']['product']['on_sale'] ? 'on sale' : 'draft') : '—',
            $row['moves']['isbns'] ?? '—',
            $row['previous_owner'] ?? '—',
            $row['ok'] ? 'ok' : implode(' ', $row['problems']),
        ])->all());

        foreach ($plan['books'] as $row) {
            foreach ($row['warnings'] as $warning) {
                $this->warn("#{$row['id']}: {$warning}");
            }
        }

        if (! $plan['ok']) {
            $this->error('Nothing moved: fix the books marked above first.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->info('Dry run: nothing was written. '.count($plan['books'])." book(s) would move to \"{$house->name}\". Add --force to apply.");

            return self::SUCCESS;
        }

        try {
            $transfer->apply($ids, $house, $reason.' (house:transfer, shell)', null, $keep);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info(count($plan['books'])." book(s) moved to \"{$house->name}\" and audited.");

        return self::SUCCESS;
    }

    /** @return list<int>|null */
    private function ids(array $args): ?array
    {
        $ids = [];

        foreach ($args as $arg) {
            foreach (preg_split('/[\s,]+/', (string) $arg, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                if (preg_match('/^(\d+)-(\d+)$/', $part, $m) && (int) $m[1] <= (int) $m[2] && (int) $m[2] - (int) $m[1] < 1000) {
                    array_push($ids, ...range((int) $m[1], (int) $m[2]));
                } elseif (ctype_digit($part)) {
                    $ids[] = (int) $part;
                } else {
                    return null;
                }
            }
        }

        return $ids === [] ? null : array_values(array_unique($ids));
    }
}
