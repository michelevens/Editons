<?php

namespace App\Platform;

use App\Models\AdminAuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The admin audit log.
 *
 * Every change made from the console is written with its row here in one
 * transaction (`AdminAudit::transaction`), so a change whose audit row cannot
 * be written does not happen. An audit log that can silently miss entries is
 * worse than none: it is trusted.
 *
 * The impersonator is filled in from the current "View as" session, so a row
 * written while support views an account says who was really acting.
 */
final class AdminAudit
{
    /**
     * Run a change and its audit row together.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function transaction(Closure $work): mixed
    {
        return DB::transaction($work);
    }

    /**
     * Write one row. Throws if it cannot — callers inside transaction() then
     * roll their change back with it.
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actor = null,
        bool $system = false,
    ): AdminAuditLog {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        $actorId = $system ? null : ($actor?->id ?? $request?->user()?->id);

        return AdminAuditLog::create([
            'actor_id' => $actorId,
            'impersonator_id' => Impersonation::impersonatorId(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason !== null ? trim($reason) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : 'console',
        ]);
    }

    /**
     * The fields of $before and $after that differ, as [before, after].
     *
     * Only what changed is stored, so a row reads as the change rather than
     * as two copies of the account.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $from = [];
        $to = [];

        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== $new) {
                $from[$key] = $old;
                $to[$key] = $new;
            }
        }

        return [$from, $to];
    }
}
