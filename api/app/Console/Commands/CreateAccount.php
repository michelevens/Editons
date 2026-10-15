<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create or update one account from the console.
 *
 * Exists because provisioning a real person's account by piping PHP through
 * `railway ssh` means their password crosses two shells that both re-parse
 * it. This takes the password on stdin or generates one, so it is never a
 * shell argument and never lands in a history file.
 *
 * Deliberately not a seeder: seeders are reset data, and this creates an
 * account somebody is going to keep.
 */
class CreateAccount extends Command
{
    protected $signature = 'account:create
        {email : The address to sign in with}
        {--name= : The display name, or set ACCOUNT_NAME to avoid shell quoting}
        {--plan=free : The plan to put them on}
        {--show-password : Print the generated password once}';

    protected $description = 'Create or update a user account';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("[{$email}] is not an email address.");

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $existed = $user->exists;

        // A password is generated rather than accepted as an argument: an
        // argument is visible in the process list and the shell history of
        // whatever machine ran the command.
        $password = Str::password(20, symbols: false);

        /*
         * The name can come from the environment.
         *
         * `railway ssh -- php artisan ...` re-parses the command in a remote
         * shell, which splits --name="Nageley J. Michel, APRN" on its spaces
         * and fails with "too many arguments". Quoting does not survive
         * either, nor does an env var assigned the same way.
         *
         * ACCOUNT_NAME_B64 does: base64 has no spaces, quotes or shell
         * metacharacters, so it crosses every boundary unchanged. Plain
         * ACCOUNT_NAME still works where only one shell is involved.
         */
        $encoded = env('ACCOUNT_NAME_B64');

        $name = $this->option('name')
            ?: ($encoded ? base64_decode($encoded, true) : null)
            ?: env('ACCOUNT_NAME');

        $user->name = $name ?: ($user->name ?: 'Author');
        $user->password = Hash::make($password);
        $user->plan = $this->option('plan');

        // Verified on creation: this is provisioned by hand for somebody who
        // already asked for it, and an unverified account cannot reach the
        // dashboard.
        $user->email_verified_at ??= now();

        $user->save();

        $this->info(($existed ? 'Updated' : 'Created')." account {$user->id} for {$email} on the {$user->plan} plan.");

        if ($this->option('show-password')) {
            $this->newLine();
            $this->warn('Password (shown once — change it after signing in):');
            $this->line($password);
        } else {
            $this->comment('Password set. Use "Forgot password" to choose your own.');
        }

        return self::SUCCESS;
    }
}
