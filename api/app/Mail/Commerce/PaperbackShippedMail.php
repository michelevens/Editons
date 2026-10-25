<?php

namespace App\Mail\Commerce;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A paperback bought in the storefront has left the printer (Lulu said
 * SHIPPED), with the carrier's tracking. Addressed to the buyer's email.
 */
class PaperbackShippedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'receipts';

    public function __construct(
        public string $buyerEmail,
        public ?string $buyerName,
        public string $orderNumber,
        public string $title,
        public ?string $carrier = null,
        public ?string $trackingId = null,
        public ?string $trackingUrl = null,
    ) {}

    public function letter(): Letter
    {
        $letter = Letter::make(__('Your paperback is on its way'))
            ->preheader(__('“:title” has shipped.', ['title' => $this->title]))
            ->subtitle(__('Shipped'))
            ->eyebrow(__('Order · :number', ['number' => $this->orderNumber]))
            ->severity('confirmed')
            ->headline(__('“:title” has shipped', ['title' => $this->title]))
            ->lede(self::greet($this->buyerName, __('your paperback has been printed and handed to the carrier.')))
            ->facts([
                __('Order') => $this->orderNumber,
                __('Carrier') => $this->carrier,
                __('Tracking number') => $this->trackingId,
            ]);

        if ($this->trackingUrl) {
            $letter = $letter->cta(__('Track your parcel'), $this->trackingUrl, showFallbackUrl: true);
        }

        return $letter->reason(__('You received this because you ordered a paperback with this address.'));
    }
}
