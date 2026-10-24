<?php

namespace App\Platform\Ops;

use App\Models\ErrorGroup;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Errors grouped by fingerprint (PlanDraft's OpsController::errors, light).
 *
 * Server exceptions arrive from the exception handler's report hook, the
 * ones Laravel would log anyway (404s and validation errors never do);
 * browser errors from POST /client-errors. Each group keeps a count, when
 * it was first and last seen, and one example — message, stack, the path
 * without its query string, and a user id. Nothing else about the person:
 * no request body, no headers, no IP.
 *
 * A resolved group that happens again is open again. An ignored one keeps
 * counting quietly.
 */
final class ErrorGroups
{
    private const TRACE_LIMIT = 8000;

    /** Set while capturing, so an error inside capture cannot recurse. */
    private static bool $capturing = false;

    public static function captureServer(Throwable $e): void
    {
        $file = self::relative($e->getFile());

        self::capture(
            source: 'server',
            type: $e::class,
            message: $e->getMessage(),
            location: "{$file}:{$e->getLine()}",
            // Where it was thrown identifies it; the message often carries an
            // id or a value that would split one bug into a thousand groups.
            fingerprint: sha1('server|'.$e::class.'|'.$file.'|'.$e->getLine()),
            trace: self::serverTrace($e),
            url: app()->bound('request') && (! app()->runningInConsole() || app()->runningUnitTests()) ? request()->path() : null,
            userId: app()->bound('request') ? request()->user()?->id : null,
        );
    }

    /**
     * One report from the browser. The fingerprint is the normalised
     * message and the first frame, with asset hashes and line positions
     * that change on every build left out.
     */
    public static function captureClient(string $message, ?string $stack, ?string $url, ?int $userId): ?ErrorGroup
    {
        $message = mb_substr(trim($message), 0, 1000);
        $frame = self::firstFrame((string) $stack);

        return self::capture(
            source: 'client',
            type: 'JavaScript',
            message: $message,
            location: $frame,
            fingerprint: sha1('client|'.self::normalise($message).'|'.self::normalise((string) $frame)),
            trace: $stack === null ? null : mb_substr($stack, 0, self::TRACE_LIMIT),
            url: $url === null ? null : self::pathOnly($url),
            userId: $userId,
        );
    }

    /** @throws ValidationException */
    public static function setStatus(ErrorGroup $group, string $status, User $actor, ?string $reason = null): void
    {
        if (! in_array($status, ['open', 'resolved', 'ignored'], true)) {
            throw ValidationException::withMessages(['status' => 'Choose open, resolved or ignored.']);
        }

        if ($group->status === $status) {
            return;
        }

        AdminAudit::transaction(function () use ($group, $status, $actor, $reason) {
            $before = $group->status;
            $group->forceFill([
                'status' => $status,
                'resolved_at' => $status === 'open' ? null : now(),
                'resolved_by' => $status === 'open' ? null : $actor->id,
            ])->save();

            AdminAudit::record('error.'.$status, $group, ['status' => $before], ['status' => $status], $reason, $actor);
        });
    }

    private static function capture(
        string $source,
        string $type,
        string $message,
        ?string $location,
        string $fingerprint,
        ?string $trace,
        ?string $url,
        ?int $userId,
    ): ?ErrorGroup {
        if (self::$capturing) {
            return null;
        }

        self::$capturing = true;

        try {
            $now = now();
            $values = [
                'source' => $source,
                'type' => mb_substr($type, 0, 191),
                'message' => mb_substr($message, 0, 2000),
                'location' => $location === null ? null : mb_substr($location, 0, 500),
                'example_trace' => $trace,
                'example_url' => $url === null ? null : mb_substr($url, 0, 500),
                'example_user_id' => $userId,
                'last_seen_at' => $now,
            ];

            // One statement, so two workers seeing the same error at once
            // add to one group rather than racing to create two. Inside its
            // own savepoint when a transaction is open: a failed write here
            // must not abort the caller's transaction (PostgreSQL would).
            DB::transaction(fn () => DB::table('error_groups')->upsert(
                [$values + ['fingerprint' => $fingerprint, 'count' => 1, 'first_seen_at' => $now, 'status' => 'open']],
                ['fingerprint'],
                [
                    ...array_keys($values),
                    'count' => DB::raw('error_groups.count + 1'),
                    // Back again after being fixed: it was not fixed.
                    'status' => DB::raw("CASE WHEN error_groups.status = 'resolved' THEN 'open' ELSE error_groups.status END"),
                    'resolved_at' => DB::raw("CASE WHEN error_groups.status = 'resolved' THEN NULL ELSE error_groups.resolved_at END"),
                ],
            ));

            return ErrorGroup::where('fingerprint', $fingerprint)->first();
        } catch (Throwable) {
            // Swallowed, not reported: reporting would come straight back
            // here. The error itself still reaches the log through Laravel.
            return null;
        } finally {
            self::$capturing = false;
        }
    }

    private static function serverTrace(Throwable $e): string
    {
        $lines = [class_basename($e).': '.mb_substr($e->getMessage(), 0, 500)];

        foreach (array_slice($e->getTrace(), 0, 30) as $i => $frame) {
            // Function and place only — never the arguments, which is where
            // passwords and manuscript text would be.
            $where = isset($frame['file']) ? self::relative($frame['file']).':'.($frame['line'] ?? '?') : '[internal]';
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $lines[] = "#{$i} {$where} {$call}()";
        }

        return mb_substr(implode("\n", $lines), 0, self::TRACE_LIMIT);
    }

    private static function relative(string $file): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : basename(dirname($file)).'/'.basename($file);
    }

    private static function firstFrame(string $stack): ?string
    {
        foreach (preg_split('/\R/', $stack) ?: [] as $line) {
            if (preg_match('~(https?://\S+|/\S+\.(?:js|jsx|mjs))(:\d+)?(:\d+)?~', $line, $m)) {
                return mb_substr(self::pathOnly($m[1]).($m[2] ?? ''), 0, 500);
            }
        }

        return null;
    }

    private static function pathOnly(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : mb_substr(strtok($url, '?#') ?: $url, 0, 500);
    }

    /** Numbers, hashes and quoted values out, so one bug is one group. */
    private static function normalise(string $text): string
    {
        $text = preg_replace('/-[A-Za-z0-9_]{8,}\.(js|css)/', '.$1', $text) ?? $text;
        $text = preg_replace('/:\d+(:\d+)?/', '', $text) ?? $text;
        $text = preg_replace('/"[^"]*"|\'[^\']*\'/', '"…"', $text) ?? $text;

        return preg_replace('/\d+/', 'N', $text) ?? $text;
    }
}
