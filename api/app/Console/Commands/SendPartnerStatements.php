<?php

namespace App\Console\Commands;

use App\Commerce\Partners\Ledger;
use App\Mail\Notify;
use App\Mail\Partners\PartnerStatementMail;
use App\Models\Partner;
use App\Models\PartnerClick;
use App\Models\PartnerCommission;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Email each partner a statement of last month's commissions — only
 * partners whose month had a commission or an adjustment, one per currency.
 * Each one sent is recorded (partner_statements), so a re-run for the same
 * month sends nothing twice. Scheduled on the 1st (routes/console.php).
 */
class SendPartnerStatements extends Command
{
    protected $signature = 'partners:statements
        {--month= : The month to report, as YYYY-MM (default: last month)}
        {--dry-run : Count who would get one, send nothing}';

    protected $description = 'Email each partner a statement of last month\'s commissions';

    public function handle(Ledger $ledger): int
    {
        $option = $this->option('month');

        if ($option !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $option)) {
            $this->error('--month must look like 2026-09.');

            return self::INVALID;
        }

        $month = $option
            ? Carbon::createFromFormat('Y-m-d', $option.'-01')->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $end = $month->copy()->addMonth();

        $rows = PartnerCommission::whereIn('kind', ['sale', 'adjustment'])
            ->where('created_at', '>=', $month)->where('created_at', '<', $end)
            ->get()
            ->groupBy(fn (PartnerCommission $c) => $c->partner_id.'|'.$c->currency);

        $sent = 0;
        $skipped = 0;

        foreach ($rows as $key => $group) {
            [$partnerId, $currency] = explode('|', $key);
            $partner = Partner::with('user')->find((int) $partnerId);

            if (! $partner?->user || DB::table('partner_statements')->where('partner_id', $partner->id)->whereDate('month', $month->toDateString())->where('currency', $currency)->exists()) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $sent++;

                continue;
            }

            $lines = $group->groupBy(fn (PartnerCommission $c) => $c->kind === 'sale' ? ($c->title ?? '—') : __('Refund adjustments'))
                ->map(fn (Collection $g, string $title) => [
                    'title' => $title,
                    'units' => $g->where('kind', 'sale')->count(),
                    'amount_cents' => (int) $g->sum(fn (PartnerCommission $c) => $c->net()),
                ])->values()->all();
            $balance = $ledger->balances($partner)[$currency] ?? ['balance_cents' => 0, 'pending_cents' => 0];
            $clicks = PartnerClick::where('partner_id', $partner->id)->where('created_at', '>=', $month)->where('created_at', '<', $end)->count();

            DB::transaction(function () use ($partner, $month, $currency, $lines, $balance, $clicks) {
                DB::table('partner_statements')->insert([
                    'partner_id' => $partner->id,
                    'month' => $month->toDateString(),
                    'currency' => $currency,
                    'amount_cents' => array_sum(array_column($lines, 'amount_cents')),
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Notify::send($partner->user, new PartnerStatementMail($partner->user, $partner, $month, $lines, $currency, $clicks, (int) $balance['balance_cents'], (int) $balance['pending_cents']));
            });

            $sent++;
        }

        $this->info(($this->option('dry-run') ? 'Would send' : 'Sent')." {$sent} statement(s) for {$month->format('F Y')}; {$skipped} skipped.");

        return self::SUCCESS;
    }
}
