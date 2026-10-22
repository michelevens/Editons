<?php

namespace App\Mail\Commerce;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A buyer's order was refunded. Addressed to the buyer's email. */
class RefundProcessedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'receipts';

    /** @param  list<string>  $itemTitles */
    public function __construct(
        public string $buyerEmail,
        public ?string $buyerName,
        public string $orderNumber,
        public int $amountCents,
        public string $currency,
        public CarbonInterface $refundedAt,
        public array $itemTitles = [],
        public ?string $paymentMethod = null,
        public ?string $refundReason = null,
        public ?string $sellerName = null,
    ) {}

    public function letter(): Letter
    {
        $amount = Money::format($this->amountCents, $this->currency);
        $seller = $this->sellerName ?? config('mail.ecrivain.brand');

        return Letter::make(__('Your refund for order :number', ['number' => $this->orderNumber]))
            ->preheader(__(':amount is on its way back to you.', ['amount' => $amount]))
            ->subtitle(__('Refund processed'))
            ->eyebrow(__('Refund · :number', ['number' => $this->orderNumber]))
            ->severity('info')
            ->headline(__(':amount is on its way back', ['amount' => $amount]))
            ->lede(self::greet($this->buyerName, __('we have refunded your order from :seller. Banks usually take 5–10 business days to show it.', ['seller' => $seller])))
            ->facts([
                __('Order') => $this->orderNumber,
                __('Refunded') => $amount,
                __('To') => $this->paymentMethod,
                __('Processed on') => $this->refundedAt->translatedFormat('F j, Y'),
                __('Reason') => $this->refundReason,
            ])
            ->items(array_map(fn (string $title) => ['title' => $title, 'badge' => __('Refunded'), 'tone' => 'neutral'], $this->itemTitles), $this->itemTitles ? __('Refunded items') : null)
            ->callout('info', __('Download links for refunded items no longer work.'))
            ->reason(__('You received this because you placed an order with :seller using this address.', ['seller' => $seller]));
    }
}
