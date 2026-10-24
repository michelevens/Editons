<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Write storage/app/release.json — the commit, branch and time — for the
 * console's Releases page (App\Platform\Trust\ReleaseInfo).
 *
 * Run on the machine that deploys, just before `railway up`: an upload is
 * not a git build, so Railway has no commit to report. The file is not in
 * git; it travels with the upload.
 */
class StampRelease extends Command
{
    protected $signature = 'platform:stamp-release';

    protected $description = 'Record the commit being deployed in storage/app/release.json';

    public function handle(): int
    {
        $git = fn (array $args) => tap(new Process(['git', ...$args], base_path()), fn (Process $p) => $p->run());

        $sha = $git(['rev-parse', 'HEAD']);

        if (! $sha->isSuccessful()) {
            $this->error('Not a git checkout: nothing to stamp.');

            return self::FAILURE;
        }

        $data = [
            'sha' => trim($sha->getOutput()),
            'branch' => trim($git(['rev-parse', '--abbrev-ref', 'HEAD'])->getOutput()) ?: null,
            'dirty' => trim($git(['status', '--porcelain', '--untracked-files=no'])->getOutput()) !== '',
            'stamped_at' => now()->toIso8601String(),
        ];

        File::ensureDirectoryExists(storage_path('app'));
        File::put(storage_path('app/release.json'), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("Stamped {$data['sha']}".($data['dirty'] ? ' (with uncommitted changes)' : '').'.');

        return self::SUCCESS;
    }
}
