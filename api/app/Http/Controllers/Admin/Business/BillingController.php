<?php

namespace App\Http\Controllers\Admin\Business;

use App\Billing\BillingReport;
use App\Billing\Payments;
use App\Billing\PlanCatalog;
use App\Billing\Refunds;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing: subscriptions, revenue, dunning, trials, cancellations, credit
 * packs and refunds. Read by all staff; refunds are the owner's.
 */
class BillingController extends Controller
{
    public const TABS = ['overview', 'subscriptions', 'trials', 'past-due', 'cancellations', 'packs'];

    public function index(Request $request, BillingReport $report, PlanCatalog $catalog): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $filters = [
            'status' => in_array($request->query('status'), [...BillingReport::STATUSES, 'cancelling'], true) ? $request->query('status') : null,
            'plan' => array_key_exists((string) $request->query('plan'), $catalog->all()) ? $request->query('plan') : null,
            'q' => mb_substr(trim((string) $request->query('q')), 0, 100) ?: null,
        ];
        $days = in_array((int) $request->query('days'), [7, 14, 30, 90, 365], true) ? (int) $request->query('days') : null;

        return Inertia::render('Admin/Business/Billing', [
            'tab' => $tab,
            'kpis' => $report->kpis(),
            'trend' => $tab === 'overview' ? $report->trend(90) : [],
            'subscriptions' => $tab === 'subscriptions' ? $report->list($filters)->withQueryString() : null,
            'filters' => $filters,
            'trials' => $tab === 'trials' ? $report->trialsEnding($days ?? 7) : [],
            'past_due' => $tab === 'past-due' ? $report->pastDue() : [],
            'cancellations' => $tab === 'cancellations' ? $report->cancellations($days ?? 90) : null,
            'packs' => $tab === 'packs' ? $report->creditPurchases() : [],
            'refunds' => $tab === 'packs' ? $report->refunds() : [],
            'days' => $days,
            'plans' => collect($catalog->all())->map(fn (array $p, string $key) => ['key' => $key, 'name' => $p['name']])->values(),
            'statuses' => BillingReport::STATUSES,
            'payments' => ['available' => Payments::available(), 'refusal' => Payments::refusal(), 'gateway' => Payments::gatewayName()],
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function refundOrder(Request $request, Order $order, Refunds $refunds): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $refund = $refunds->refundOrder(
            $order,
            isset($data['amount']) ? (int) round($data['amount'] * 100) : null,
            $data['reason'],
            $request->user(),
        );

        return back()->with('platform_notice', 'Refund '.($refund->status === 'succeeded' ? 'made' : 'requested').' for order '.$order->number.'.'
            .($refund->gateway === 'stripe' ? ' The order updates when Stripe confirms it.' : ''));
    }

    public function refundCredits(Request $request, CreditTransaction $transaction, Refunds $refunds): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);

        $refunds->refundCreditPurchase($transaction, $data['reason'], $request->user());

        return back()->with('platform_notice', 'Credit pack refunded, and its unspent credits taken back.');
    }
}
