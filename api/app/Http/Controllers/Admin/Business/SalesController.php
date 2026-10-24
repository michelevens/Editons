<?php

namespace App\Http\Controllers\Admin\Business;

use App\Billing\Payments;
use App\Commerce\Commerce;
use App\Commerce\Payouts;
use App\Commerce\PlatformSales;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Book sales across the platform: orders, royalties owed per author, the
 * payouts ledger, best sellers and the last 30 days. Read by all staff;
 * recording a payout (and refunding, on the Billing page's route) is the
 * owner's.
 */
class SalesController extends Controller
{
    public function index(Request $request, PlatformSales $sales): Response
    {
        $filters = [
            'status' => in_array($request->query('status'), Order::STATUSES, true) ? $request->query('status') : null,
            'currency' => in_array($request->query('currency'), config('commerce.currencies'), true) ? $request->query('currency') : null,
            'q' => mb_substr(trim((string) $request->query('q')), 0, 100) ?: null,
        ];

        return Inertia::render('Admin/Business/Sales', [
            'enabled' => Commerce::enabled(),
            'totals' => $sales->totals(),
            'totals_30d' => $sales->totals(30),
            'daily' => $sales->daily(30),
            'top' => $sales->top(30),
            'orders' => $sales->orders($filters),
            'filters' => $filters,
            'royalties' => $sales->royalties(),
            'payouts' => $sales->payouts(),
            'methods' => collect(Payout::METHODS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'currencies' => config('commerce.currencies'),
            'statuses' => Order::STATUSES,
            'fee' => config('commerce.stripe_fee'),
            'platform_rate' => (float) config('commerce.platform_rate'),
            'payments' => ['available' => Payments::available(), 'refusal' => Payments::refusal()],
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function payout(Request $request, User $user, Payouts $payouts): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(config('commerce.currencies'))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', Rule::in(array_keys(Payout::METHODS))],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $payout = $payouts->record($user, [
            'currency' => $data['currency'],
            'amount_cents' => (int) round($data['amount'] * 100),
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_on' => $data['paid_on'],
            'note' => $data['note'] ?? null,
        ], $data['reason'], $request->user());

        return back()->with('platform_notice', 'Payout of '.number_format($payout->amount_cents / 100, 2).' '.strtoupper($payout->currency).' to '.$user->name.' recorded.');
    }
}
