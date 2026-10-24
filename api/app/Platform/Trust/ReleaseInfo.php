<?php

namespace App\Platform\Trust;

use App\Platform\Features;
use App\Platform\Ops\Health;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * What is deployed, read-only: version, runtime, drivers, flags, migrations
 * and whether the worker and the scheduler are alive.
 *
 * The commit comes from Railway's RAILWAY_GIT_COMMIT_SHA when the service
 * builds from GitHub. Écrivain deploys with `railway up` (an upload, not a
 * git build), so that variable is usually absent; then from
 * storage/app/release.json, which `php artisan platform:stamp-release`
 * writes before an upload; then, on a developer's machine, from .git. When
 * none is there the page says "unknown" rather than guessing.
 */
final class ReleaseInfo
{
    /** Railway's variables that are safe to show (no secrets among them). */
    private const RAILWAY = [
        'RAILWAY_SERVICE_NAME', 'RAILWAY_ENVIRONMENT_NAME', 'RAILWAY_DEPLOYMENT_ID',
        'RAILWAY_REPLICA_ID', 'RAILWAY_REPLICA_REGION', 'RAILWAY_GIT_BRANCH', 'RAILWAY_GIT_COMMIT_SHA',
    ];

    public function __construct(private readonly Migrator $migrator, private readonly Health $health) {}

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $release = self::stamp();
        $sha = self::env('RAILWAY_GIT_COMMIT_SHA') ?? ($release['sha'] ?? null) ?? self::gitHead();

        return [
            'version' => [
                'sha' => $sha,
                'short' => $sha ? substr($sha, 0, 10) : null,
                'source' => match (true) {
                    self::env('RAILWAY_GIT_COMMIT_SHA') !== null => 'RAILWAY_GIT_COMMIT_SHA',
                    isset($release['sha']) => 'storage/app/release.json',
                    $sha !== null => '.git (this machine)',
                    default => null,
                },
                'dirty' => $release['dirty'] ?? null,
                'branch' => self::env('RAILWAY_GIT_BRANCH') ?? ($release['branch'] ?? null),
                'deployed_at' => $release['stamped_at'] ?? self::configCachedAt(),
                'deployed_at_source' => isset($release['stamped_at']) ? 'release.json' : (self::configCachedAt() ? 'config cache written (≈ deploy)' : null),
            ],
            'railway' => collect(self::RAILWAY)->mapWithKeys(fn ($k) => [$k => self::env($k)])->filter()->all(),
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'timezone' => (string) config('app.timezone'),
            ],
            'drivers' => [
                'database' => (string) config('database.default'),
                'queue' => (string) config('queue.default'),
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'mail' => (string) config('mail.default'),
                'files' => (string) config('filesystems.disks.files.driver'),
                'log' => (string) config('logging.default'),
                'editorial_ai' => (string) config('editorial.driver'),
                'payments' => (string) config('commerce.gateway'),
            ],
            'features' => collect(Features::catalog())->map(fn (array $f) => [
                'key' => $f['key'],
                'label' => $f['label'],
            ] + app(Features::class)->explain($f['key']))->values()->all(),
            'migrations' => $this->migrations(),
            'heartbeats' => collect($this->health->checks())
                ->whereIn('key', ['worker', 'scheduler'])
                ->values()
                ->all(),
        ];
    }

    /** @return array{ran: ?int, pending: list<string>, error: ?string} */
    private function migrations(): array
    {
        try {
            $repository = $this->migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return ['ran' => 0, 'pending' => [], 'error' => 'The migrations table does not exist.'];
            }

            $ran = $repository->getRan();
            $files = array_keys($this->migrator->getMigrationFiles([database_path('migrations')]));

            return ['ran' => count($ran), 'pending' => array_values(array_diff($files, $ran)), 'error' => null];
        } catch (Throwable $e) {
            return ['ran' => null, 'pending' => [], 'error' => 'Could not read: '.mb_substr($e->getMessage(), 0, 160)];
        }
    }

    /** @return array<string, mixed> */
    public static function stamp(): array
    {
        $path = storage_path('app/release.json');

        try {
            return File::exists($path) ? (json_decode(File::get($path), true) ?: []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function gitHead(): ?string
    {
        foreach ([base_path('.git'), dirname(base_path()).'/.git'] as $git) {
            try {
                if (is_file($git)) {
                    // A worktree: ".git" is a pointer file.
                    $pointer = trim((string) file_get_contents($git));
                    $git = str_starts_with($pointer, 'gitdir:') ? trim(substr($pointer, 7)) : null;
                }

                if (! $git || ! is_dir($git) || ! is_file("{$git}/HEAD")) {
                    continue;
                }

                $head = trim((string) file_get_contents("{$git}/HEAD"));

                if (preg_match('/^[0-9a-f]{40}$/', $head)) {
                    return $head;
                }

                if (str_starts_with($head, 'ref: ')) {
                    $ref = substr($head, 5);
                    $common = is_file("{$git}/commondir") ? realpath($git.'/'.trim((string) file_get_contents("{$git}/commondir"))) : $git;

                    foreach (array_filter([$git, $common]) as $dir) {
                        if (is_file("{$dir}/{$ref}")) {
                            return trim((string) file_get_contents("{$dir}/{$ref}"));
                        }

                        if (is_file("{$dir}/packed-refs") && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', (string) file_get_contents("{$dir}/packed-refs"), $m)) {
                            return $m[1];
                        }
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private static function configCachedAt(): ?string
    {
        $path = app()->getCachedConfigPath();

        return is_file($path) ? date(DATE_ATOM, (int) filemtime($path)) : null;
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? null : (string) $value;
    }
}
