<?php

namespace App\Console\Commands;

use App\Platform\Trust\AccountExporter;
use Illuminate\Console\Command;

/** Delete account exports whose link has expired; the rows stay as history. */
class PurgeAccountExports extends Command
{
    protected $signature = 'platform:purge-account-exports';

    protected $description = 'Delete expired account data exports from storage';

    public function handle(): int
    {
        $this->info(AccountExporter::purgeExpired().' expired account export(s) deleted.');

        return self::SUCCESS;
    }
}
