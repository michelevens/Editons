<?php

namespace App\Billing;

use App\Commerce\Fulfilment;
use App\Commerce\Gateway\PaymentGateway;
use App\Models\CreditTransaction;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Refunds started from the console: owner only, always with a reason.
 *
 * Through the gateway interface, never around it. Refused outright when the
 * gateway cannot move money (App\Billing\Payments: production without
 * Stripe), with a sentence saying so.
 *
 * With Stripe, the order changes when Stripe's charge.refunded webhook
 * arrives — the same path a refund made in Stripe's dashboard takes — so
 * the console never claims money moved before Stripe says it did. The fake
 * gateway has no webhook, so its refund is applied straight away through
 * the same Fulfilment code.
 *
 * Subscription invoices are not refunded here: the console keeps no invoice
 * records (Stripe does), and a partial refund of a period is a decision for
 * Stripe's dashboard, where proration is shown.
 */
final class Refunds
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly CreditLedger $ledger,
        private readonly Fulfilment $fulfilment,
    ) {}

    /** Refund all or part of a storefront order. */
    public function refundOrder(Order $order, ?int $amountCents, string $reason, User $actor): Refund
    {
        self::assertAvailable();

        if (! in_array($order->status, Order::COUNTED, true)) {
            throw ValidationException::withMessages(['refund' => 'Only a paid order can be refunded; this one is '.str_replace('_', ' ', $order->status).'.']);
        }

        if (! $order->stripe_payment_intent) {
            throw ValidationException::withMessages(['refund' => 'This order has no payment to refund.']);
        }

        $refundable = $order->total_cents - $order->refunded_cents;
        $amount = $amountCents ?? $refundable;

        if ($amount < 1 || $amount > $refundable) {
            throw ValidationException::withMessages(['amount' => 'Refund between 0.01 and '.number_format($refundable / 100, 2).' '.strtoupper($order->currency).'.']);
        }

        $result = $this->callGateway([
            'payment_intent' => $order->stripe_payment_intent,
            'amount_cents' => $amount,
            // The same refund asked for twice (a double submit) is one refund.
            'idempotency_key' => "console-refund-order-{$order->id}-{$order->refunded_cents}-{$amount}",
            'metadata' => ['order_id' => (string) $order->id, 'order_number' => $order->number, 'source' => 'console'],
        ], fn (?array $gateway, ?string $error) => $this->record(
            ['order_id' => $order->id, 'amount_cents' => $amount, 'currency' => $order->currency],
            $gateway,
            $error,
            $reason,
            $actor,
            'refund.order',
            $order,
            ['refunded_cents' => $order->refunded_cents, 'status' => $order->status],
        ));

        if ($this->gateway->name() === 'fake' && $result->status === 'succeeded') {
            // What Stripe's charge.refunded would carry: the cumulative total.
            $this->fulfilment->chargeRefunded([
                'payment_intent' => $order->stripe_payment_intent,
                'amount_refunded' => $order->refunded_cents + $amount,
                'metadata' => ['order_id' => (string) $order->id],
            ]);
        }

        return $result;
    }

    /**
     * Refund a credit-pack purchase, and take back the credits it bought
     * that are left (never below zero: what was spent was used).
     */
    public function refundCreditPurchase(CreditTransaction $purchase, string $reason, User $actor): Refund
    {
        self::assertAvailable();

        if ($purchase->kind !== 'purchase') {
            throw ValidationException::withMessages(['refund' => 'Only a credit-pack purchase can be refunded here.']);
        }

        if (! str_starts_with((string) $purchase->external_reference, 'pi_')) {
            throw ValidationException::withMessages(['refund' => 'This purchase has no Stripe payment on record to refund.']);
        }

        $pack = config("billing.packs.{$purchase->label}");

        if (! is_array($pack)) {
            throw ValidationException::withMessages(['refund' => 'This purchase is not a pack this deployment knows the price of.']);
        }

        if (Refund::where('credit_transaction_id', $purchase->id)->where('status', '!=', 'failed')->exists()) {
            throw ValidationException::withMessages(['refund' => 'This purchase has already been refunded.']);
        }

        $amount = (int) round($pack['price'] * 100);
        $user = $purchase->user;

        return $this->callGateway([
            'payment_intent' => $purchase->external_reference,
            'amount_cents' => $amount,
            'idempotency_key' => "console-refund-credits-{$purchase->id}",
            'metadata' => ['credit_transaction_id' => (string) $purchase->id, 'source' => 'console'],
        ], function (?array $gateway, ?string $error) use ($purchase, $amount, $reason, $actor, $user) {
            $refund = $this->record(
                ['credit_transaction_id' => $purchase->id, 'amount_cents' => $amount, 'currency' => 'usd'],
                $gateway,
                $error,
                $reason,
                $actor,
                'refund.credits',
                $user,
                ['balance' => $this->ledger->balanceFor($user)],
            );

            if ($error === null) {
                $take = min($purchase->amount, max(0, $this->ledger->balanceFor($user)));

                if ($take > 0) {
                    $this->ledger->grant($user, -$take, 'pack_refund', "refund:{$refund->id}", 'admin_deduct');
                }
            }

            return $refund;
        });
    }

    /**
     * Ask the gateway, then write the refund row and its audit row in one
     * transaction. A gateway failure is written too (as failed) before the
     * error is shown, so a refused refund is not lost from the record.
     *
     * @param  array<string, mixed>  $params
     * @param  \Closure(?array, ?string): Refund  $write
     */
    private function callGateway(array $params, \Closure $write): Refund
    {
        try {
            $gateway = $this->gateway->refund($params);
            $error = null;
        } catch (Throwable $e) {
            report($e);
            $gateway = null;
            $error = $e->getMessage();
        }

        $refund = AdminAudit::transaction(fn () => $write($gateway, $error));

        if ($error !== null) {
            throw ValidationException::withMessages(['refund' => 'The payment provider refused the refund: '.mb_substr($error, 0, 200)]);
        }

        return $refund;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{id: string, status: string}|null  $gateway
     * @param  array<string, mixed>  $before
     */
    private function record(array $row, ?array $gateway, ?string $error, string $reason, User $actor, string $action, mixed $subject, array $before): Refund
    {
        $refund = Refund::create($row + [
            'gateway' => $this->gateway->name(),
            'gateway_refund_id' => $gateway['id'] ?? null,
            'status' => $error !== null ? 'failed' : (in_array($gateway['status'] ?? '', ['succeeded', 'pending'], true) ? $gateway['status'] : 'pending'),
            'reason' => trim($reason),
            'actor_id' => $actor->id,
        ]);

        AdminAudit::record(
            $error !== null ? $action.'.failed' : $action,
            $subject,
            $before,
            [
                'refund_id' => $refund->id,
                'amount_cents' => $refund->amount_cents,
                'currency' => $refund->currency,
                'gateway' => $refund->gateway,
                'gateway_refund_id' => $refund->gateway_refund_id,
                'status' => $refund->status,
            ] + ($error !== null ? ['error' => mb_substr($error, 0, 200)] : []),
            $reason,
            $actor,
        );

        return $refund;
    }

    /** @throws ValidationException */
    private static function assertAvailable(): void
    {
        if ($refusal = Payments::refusal()) {
            throw ValidationException::withMessages(['refund' => $refusal.' Refund it in Stripe once it is set up.']);
        }
    }
}
