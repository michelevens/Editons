<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Platform\AccountAdmin;
use App\Platform\AdminAudit;
use Illuminate\Console\Command;

/**
 * Take platform staff rights away. The account itself, and its books, stay.
 *
 * Refuses to demote the last owner: a platform with no owner can only get
 * one back through this shell, so the command says so instead.
 */
class PlatformDemote extends Command
{
    protected $signature = 'platform:demote {email : The staff account to demote}';

    protected $description = 'Remove platform staff rights from an account';

    public function handle(AccountAdmin $accounts): int
    {
        $user = User::where('email', strtolower(trim((string) $this->argument('email'))))->first();

        if (! $user || ! $user->isPlatformAdmin()) {
            $this->error('No platform staff account has that address.');

            return self::FAILURE;
        }

        if ($user->isPlatformOwner() && User::where('platform_role', 'owner')->count() === 1) {
            $this->error('That is the only owner. Make another owner first (platform:admin), then demote this one.');

            return self::FAILURE;
        }

        AdminAudit::transaction(function () use ($user, $accounts) {
            $before = $user->platform_role;
            $user->platform_role = null;
            $user->save();
            $accounts->revokeSessions($user);

            AdminAudit::record('account.role_changed', $user, ['platform_role' => $before], ['platform_role' => null], 'platform:demote (shell)', system: true);
        });

        $this->info("{$user->email} is no longer platform staff. Their sessions have ended.");

        return self::SUCCESS;
    }
}
