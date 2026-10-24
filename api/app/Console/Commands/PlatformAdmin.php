<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Platform\AccountAdmin;
use App\Platform\AdminAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Make an account platform staff — the only way the first one is made.
 *
 * From the shell (`railway ssh` in production, docs/ADMIN.md), never from a
 * form and never from a seeder (Educasoftio's rule: "no seeder creates a
 * platform account"). An existing account is promoted; a new one is created
 * with a generated password, printed once, which is never a shell argument
 * and so never lands in a history file (PlanDraft's AddSuperAdmin).
 */
class PlatformAdmin extends Command
{
    protected $signature = 'platform:admin
        {email : The address of the account to promote or create}
        {--role=owner : owner or support}
        {--name= : The display name, for a new account}';

    protected $description = 'Make an account platform staff (owner or support)';

    public function handle(AccountAdmin $accounts): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("[{$email}] is not an email address.");

            return self::FAILURE;
        }

        if (! in_array($role, User::PLATFORM_ROLES, true)) {
            $this->error('The role is owner or support.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        $password = null;

        AdminAudit::transaction(function () use (&$user, &$password, $email, $role, $accounts) {
            $before = $user?->platform_role;

            if (! $user) {
                $password = Str::password(24, symbols: false);
                $user = new User;
                $user->email = $email;
                $user->name = $this->option('name') ?: 'Ecrivain staff';
                $user->password = Hash::make($password);
                // Provisioned by hand: verified, like account:create.
                $user->email_verified_at = now();
            }

            $user->platform_role = $role;
            $user->save();

            // New powers: sign in again (and pass two-factor) to use them.
            if ($before !== $role && $user->wasRecentlyCreated === false) {
                $accounts->revokeSessions($user);
            }

            AdminAudit::record('account.role_changed', $user, ['platform_role' => $before], ['platform_role' => $role], 'platform:admin (shell)', system: true);
        });

        $this->info(($password ? 'Created' : 'Promoted')." account {$user->id} ({$email}) as platform {$role}.");

        if ($password) {
            $this->newLine();
            $this->warn('Password (shown once — store it in your password manager now):');
            $this->line($password);
        }

        $this->newLine();
        $this->comment('Next: sign in and turn on two-factor sign-in. The console opens only its set-up page until you do.');

        return self::SUCCESS;
    }
}
