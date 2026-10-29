<?php

namespace App\Console\Commands;

use App\Commerce\SalesReport;
use App\Mail\Notify;
use App\Models\OrderItem;
use App\Models\RoyaltyStatement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Email each author their storefront royalties for a month.
 *
 * Not scheduled. To turn it on, add to routes/console.php:
 *
 *     Schedule::command('commerce:royalty-statements')->monthlyOn(1, '9:00');
 *
 * The month defaults to the previous calendar month. Only authors who sold
 * something that month get a statement, one per currency. Each one sent is
 * recorded (royalty_statements, unique per author, month and currency), so
 * running the command twice for the same month sends nothing the second
 * time — safe to retry after a failure part-way through.
 *
 * The statement reports what was earned; it does not pay it. Payouts to
 * authors are not automated (no Stripe Connect yet), so no payout date is
 * printed.
 */
class SendRoyaltyStatements extends Command
{
    protected $signature = 'commerce:royalty-statements
        {--month= : The month to report, as YYYY-MM (default: last month)}
        {--user= : Only this user id}
        {--dry-run : Count who would get one, send nothing}';

    protected $description = 'Email each author a statement of last month\'s storefront royalties';

    public function handle(): int
    {
        $option = $this->option('month');

        if ($option !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $option)) {
            $this->error('--month must look like 2026-09.');

            return self::INVALID;
        }

        $month = $option
            ? Carbon::createFromFormat('Y-m-d', $option.'-01')->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $sellerIds = OrderItem::query()
            ->when($this->option('user'), fn ($q, $id) => $q->where('seller_id', $id))
            ->whereHas('order', fn ($q) => $q
                ->whereNotNull('paid_at')
                // A day either side: each seller's month is cut in their
                // own timezone (SalesReport::month decides exactly).
                ->where('paid_at', '>=', $month->copy()->subDay())
                ->where('paid_at', '<', $month->copy()->addMonth()->addDay()))
            ->distinct()
            ->pluck('seller_id');

        $sent = 0;
        $skipped = 0;

        // House accounts' sales are the platform's revenue: no statement,
        // and nobody to send one to (ADR — house catalog).
        foreach (User::whereIn('id', $sellerIds)->exceptHouse()->orderBy('id')->get() as $seller) {
            foreach (SalesReport::month($seller, $month) as $currency => $statement) {
                $already = RoyaltyStatement::where('user_id', $seller->id)
                    ->whereDate('month', $month->toDateString())
                    ->where('currency', $currency)
                    ->exists();

                if ($already) {
                    $skipped++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $sent++;

                    continue;
                }

                $previous = RoyaltyStatement::where('user_id', $seller->id)
                    ->whereDate('month', $month->copy()->subMonth()->toDateString())
                    ->where('currency', $currency)
                    ->value('royalty_cents');

                // Recorded in one transaction with the hand-off to the mailer
                // (which queues): anything that throws takes the row with
                // it, and a re-run tries that statement again.
                DB::transaction(function () use ($seller, $month, $currency, $statement, $previous) {
                    $row = new RoyaltyStatement;
                    $row->forceFill([
                        'user_id' => $seller->id,
                        'month' => $month->toDateString(),
                        'currency' => $currency,
                        'units' => $statement['units'],
                        'royalty_cents' => $statement['royalty_cents'],
                        'sent_at' => now(),
                    ])->save();

                    Notify::royaltyStatement(
                        $seller,
                        month: $month,
                        lines: $statement['lines'],
                        currency: $currency,
                        statementUrl: route('sales.overview'),
                        previousTotalCents: $previous !== null ? (int) $previous : null,
                    );
                });

                $sent++;
            }
        }

        $this->info(($this->option('dry-run') ? 'Would send' : 'Sent')." {$sent} statement(s) for {$month->format('F Y')}; {$skipped} already sent.");

        return self::SUCCESS;
    }
}
