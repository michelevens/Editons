<?php

namespace App\Console\Commands;

use App\Platform\House\HouseAccounts;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Create a house publisher account (ADR — house catalog).
 *
 * From the shell only, like platform:admin: a platform-owned imprint that
 * nobody signs in to. Safe to run twice — an existing slug is reported, not
 * duplicated. No password is printed: there is none anybody can use.
 *
 *   php artisan house:create ecrivain-prepa --name="Écrivain Prépa" --reason="…"
 */
class HouseCreate extends Command
{
    protected $signature = 'house:create
        {slug : Its stable name, e.g. ecrivain-prepa}
        {--name= : The imprint\'s public name, e.g. "Écrivain Prépa"}
        {--reason= : Why, for the audit log}';

    protected $description = 'Create a platform house publisher account (nobody signs in to it)';

    public function handle(HouseAccounts $accounts): int
    {
        $slug = (string) $this->argument('slug');
        $name = (string) $this->option('name');
        $reason = trim((string) $this->option('reason'));

        if ($name === '' || $reason === '') {
            $this->error('Give --name (the imprint, never a person) and --reason.');

            return self::INVALID;
        }

        $existing = $accounts->find($slug);

        try {
            $house = $accounts->create($slug, $name, $reason.' (house:create, shell)');
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info(($existing ? 'Already exists' : 'Created').": house account #{$house->id} \"{$house->name}\" (slug {$house->house_slug}).");
        $this->line('Move books in with: php artisan house:transfer <book ids…> --to='.$house->house_slug.' --reason="…"');

        return self::SUCCESS;
    }
}
