<?php

namespace App\Platform\Ops;

use App\Models\BookExport;
use App\Models\MailLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health checks that report what they measured, and "unknown" when they
 * could not measure (ShiftPulse's rule: a dashboard that guesses green is
 * worse than one that admits it does not know).
 *
 * Each check is ok | warning | failing | unknown, with a sentence saying
 * what was measured. Nothing here calls a paid service: Stripe and the
 * mail provider are checked for configuration, not pinged.
 */
final class Health
{
    /** @return array<int, array{key: string, label: string, status: string, detail: string, ms?: int}> */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->filesDisk(),
            $this->exportStorage(),
            $this->queueWorker(),
            $this->scheduler(),
            $this->mail(),
            $this->stripe(),
        ];
    }

    private function database(): array
    {
        $start = hrtime(true);

        try {
            DB::select('select 1');

            return self::check('database', 'Database', 'ok', 'Answered a query.', $start);
        } catch (Throwable $e) {
            return self::check('database', 'Database', 'failing', 'Query failed: '.self::short($e), $start);
        }
    }

    /** Write, read back and delete a small file on the disk books and exports live on. */
    private function filesDisk(): array
    {
        $start = hrtime(true);
        $disk = Storage::disk('files');
        $path = 'health/probe-'.Str::random(16).'.txt';
        $body = 'ecrivain-health '.now()->toIso8601String();
        $driver = config('filesystems.disks.files.driver');

        try {
            $disk->put($path, $body);
            $read = $disk->get($path);
            $disk->delete($path);

            return $read === $body
                ? self::check('files', "Files disk ({$driver})", 'ok', 'Wrote, read back and deleted a probe file.', $start)
                : self::check('files', "Files disk ({$driver})", 'failing', 'The probe file read back different from what was written.', $start);
        } catch (Throwable $e) {
            try {
                $disk->delete($path);
            } catch (Throwable) {
                // Nothing to clean if the write failed.
            }

            return self::check('files', "Files disk ({$driver})", 'failing', 'Probe failed: '.self::short($e), $start);
        }
    }

    /** Free space where exports are written — measurable on a local disk only. */
    private function exportStorage(): array
    {
        $ready = BookExport::where('status', 'ready');
        $summary = number_format((clone $ready)->count()).' ready exports, '
            .self::bytes((int) (clone $ready)->sum('byte_size')).' recorded.';

        if (config('filesystems.disks.files.driver') !== 'local') {
            return ['key' => 'export_storage', 'label' => 'Export storage', 'status' => 'unknown',
                'detail' => 'The bucket does not report free space. '.$summary];
        }

        $root = (string) config('filesystems.disks.files.root');
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);

        if ($free === false || $total === false || $total <= 0) {
            return ['key' => 'export_storage', 'label' => 'Export storage', 'status' => 'unknown',
                'detail' => 'Free space could not be read. '.$summary];
        }

        $pct = $free / $total * 100;

        return ['key' => 'export_storage', 'label' => 'Export storage',
            'status' => $pct < 5 ? 'failing' : ($pct < 15 ? 'warning' : 'ok'),
            'detail' => self::bytes((int) $free).' free of '.self::bytes((int) $total).' ('.round($pct).'%). '.$summary];
    }

    private function queueWorker(): array
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            return ['key' => 'worker', 'label' => 'Queue worker', 'status' => 'ok',
                'detail' => 'The queue is sync: jobs run inside the request, no worker needed.'];
        }

        $beat = DB::table('heartbeats')->where('key', Heartbeats::WORKER)->first();

        if (! $beat) {
            return ['key' => 'worker', 'label' => 'Queue worker', 'status' => 'unknown',
                'detail' => 'No worker has reported since heartbeats were added.'];
        }

        $seen = Carbon::parse($beat->last_finished_at ?? $beat->updated_at);
        $silent = (int) config('platform.ops.worker_silent_minutes', 3);

        return ['key' => 'worker', 'label' => 'Queue worker',
            'status' => $seen->copy()->addMinutes($silent)->isPast() ? 'failing' : 'ok',
            'detail' => 'Last seen '.$seen->diffForHumans().($beat->host ? " on {$beat->host}" : '').'.'];
    }

    private function scheduler(): array
    {
        $last = DB::table('heartbeats')->where('kind', 'schedule')->max('last_started_at');

        if (! $last) {
            return ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => 'unknown',
                'detail' => 'No scheduled command has reported since heartbeats were added.'];
        }

        $at = Carbon::parse($last);

        // exports:expire runs every five minutes.
        return ['key' => 'scheduler', 'label' => 'Scheduler',
            'status' => $at->copy()->addMinutes(15)->isPast() ? 'failing' : 'ok',
            'detail' => 'A scheduled command last started '.$at->diffForHumans().'.'];
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $since = now()->subDay();
        $sent = MailLog::where('created_at', '>=', $since)->where('status', 'sent')->count();
        $failed = MailLog::where('created_at', '>=', $since)->where('status', 'failed')->count();
        $tally = " Last 24 hours: {$sent} sent, {$failed} failed.";

        [$status, $detail] = match ($mailer) {
            'log', 'array' => ['warning', "Mail goes to the {$mailer} driver: nothing reaches an inbox."],
            'resend' => filled(config('services.resend.key'))
                ? ['ok', 'Resend is configured (delivery itself is not measured here).']
                : ['failing', 'MAIL_MAILER is resend but RESEND_API_KEY is not set.'],
            'smtp' => filled(config('mail.mailers.smtp.host'))
                ? ['ok', 'SMTP is configured (delivery itself is not measured here).']
                : ['failing', 'MAIL_MAILER is smtp but no host is set.'],
            default => ['unknown', "Mailer {$mailer} is not one this page knows how to check."],
        };

        if ($status === 'ok' && $failed > 0 && $failed >= $sent) {
            $status = 'warning';
        }

        return ['key' => 'mail', 'label' => 'Mail transport', 'status' => $status, 'detail' => $detail.$tally];
    }

    private function stripe(): array
    {
        $secret = filled(config('cashier.secret'));
        $webhook = filled(config('cashier.webhook.secret'));

        if (! $secret) {
            return ['key' => 'stripe', 'label' => 'Stripe', 'status' => 'warning',
                'detail' => 'Not configured: subscriptions and the storefront cannot take payments.'];
        }

        return ['key' => 'stripe', 'label' => 'Stripe', 'status' => $webhook ? 'ok' : 'warning',
            'detail' => $webhook
                ? 'Keys and webhook secret configured (Stripe itself is not called from here).'
                : 'Keys configured, but no webhook secret: payments would not be confirmed.'];
    }

    private static function check(string $key, string $label, string $status, string $detail, int|float $start): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail,
            'ms' => (int) round((hrtime(true) - $start) / 1_000_000)];
    }

    private static function short(Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 200);
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (int) $value : round($value, 1)).' '.$units[$i];
    }
}
