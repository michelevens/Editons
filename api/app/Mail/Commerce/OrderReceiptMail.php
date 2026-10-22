<?php

namespace App\Mail\Commerce;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A reader bought books from an author's storefront.
 *
 * Addressed to an email address, not an account — buyers do not need one.
 * Download links must be secure (signed, expiring) when they arrive here;
 * this email prints them, it does not mint them.
 */
class OrderReceiptMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'receipts';

    /**
     * @param  list<array{title: string, format: string, amount_cents: int, download_url?: ?string, downloads?: list<array{detail: string, url: string}>}>  $items
     */
    public function __construct(
        public string $buyerEmail,
        public ?string $buyerName,
        public string $orderNumber,
        public array $items,
        public int $totalCents,
        public string $currency,
        public CarbonInterface $placedAt,
        public ?string $licenseKey = null,
        public ?string $licenseTerms = null,
        public ?CarbonInterface $linksExpireAt = null,
        public ?string $receiptUrl = null,
        public ?int $taxCents = null,
        public ?string $sellerName = null,
        public ?string $paymentMethod = null,
    ) {}

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $total = Money::format($this->totalCents, $this->currency);
        $count = count($this->items);
        $seller = $this->sellerName ?? $brand;

        $rows = array_map(fn (array $item) => [
            $item['title'],
            $item['format'],
            Money::format((int) $item['amount_cents'], $this->currency),
        ], $this->items);

        if ($this->taxCents) {
            $rows[] = [__('Tax'), '', Money::format($this->taxCents, $this->currency)];
        }

        // One link per item (download_url), or one per file when an item
        // delivers several formats (downloads: [{detail, url}]).
        $downloads = [];

        foreach ($this->items as $item) {
            if (! empty($item['download_url'])) {
                $downloads[] = [
                    'title' => __('Download :title', ['title' => $item['title']]),
                    'detail' => $item['format'],
                    'url' => $item['download_url'],
                ];
            }

            foreach ($item['downloads'] ?? [] as $file) {
                $downloads[] = [
                    'title' => __('Download :title', ['title' => $item['title']]),
                    'detail' => (string) $file['detail'],
                    'url' => (string) $file['url'],
                ];
            }
        }

        $letter = Letter::make(__('Your order :number from :seller', ['number' => $this->orderNumber, 'seller' => $seller]))
            ->preheader(trans_choice('{1} :total paid. Your book is ready to download.|[2,*] :total paid. Your :count books are ready to download.', $count, ['total' => $total, 'count' => $count]))
            ->subtitle(__('Thank you for your order'))
            ->eyebrow(__('Receipt · :number', ['number' => $this->orderNumber]))
            ->severity('confirmed')
            ->headline(trans_choice('{1} Your book is ready|[2,*] Your books are ready', $count))
            ->lede(self::greet($this->buyerName, __('thank you for buying from :seller. Your receipt and downloads are below.', ['seller' => $seller])))
            ->table([__('Item'), __('Format'), __('Price')], $rows, [__('Total paid'), '', $total], __('Your order'));

        if ($downloads !== []) {
            $letter->items($downloads, __('Your downloads'));
        }

        $letter->facts([
            __('Order') => $this->orderNumber,
            __('Date') => $this->placedAt->translatedFormat('F j, Y'),
            __('Paid with') => $this->paymentMethod,
            __('Sold by') => $this->sellerName,
            __('License key') => $this->licenseKey,
        ]);

        if ($this->receiptUrl) {
            $letter->cta(__('View your order'), $this->receiptUrl);
        } elseif ($downloads !== []) {
            $letter->cta(count($downloads) === 1 ? __('Download your book') : __('Download your first book'), $downloads[0]['url']);
        }

        $terms = $this->licenseTerms
            ?? __('These files are for your personal reading. Please do not share the download links — they are tied to your order.');

        return $letter
            ->callout('info', $this->linksExpireAt
                ? $terms.' '.__('The links work until :date; write to us if you need them again after that.', ['date' => $this->linksExpireAt->translatedFormat('F j, Y')])
                : $terms, __('About your license'))
            ->reason(__('You received this because you placed an order with :seller using this address.', ['seller' => $seller]));
    }
}
