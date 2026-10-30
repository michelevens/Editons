<?php

namespace App\Mail\Commerce;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A pre-ordered book has been released (App\Commerce\Preorders): its
 * licence is issued and the download links are here. Addressed to the
 * buyer's email.
 */
class PreorderReleasedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'receipts';

    /**
     * @param  list<array{title: string, downloads: list<array{detail: string, url: string}>}>  $items
     */
    public function __construct(
        public string $buyerEmail,
        public ?string $buyerName,
        public string $orderNumber,
        public array $items,
        public ?string $licenseKey = null,
        public ?CarbonInterface $linksExpireAt = null,
        public ?string $receiptUrl = null,
    ) {}

    public function letter(): Letter
    {
        $titles = array_column($this->items, 'title');
        $downloads = [];

        foreach ($this->items as $item) {
            foreach ($item['downloads'] as $file) {
                $downloads[] = [
                    'title' => __('Download :title', ['title' => $item['title']]),
                    'detail' => (string) $file['detail'],
                    'url' => (string) $file['url'],
                ];
            }
        }

        $letter = Letter::make(__('Your pre-order is ready to download'))
            ->preheader(__('“:title” is out today.', ['title' => $titles[0] ?? '']))
            ->subtitle(__('Released'))
            ->eyebrow(__('Order · :number', ['number' => $this->orderNumber]))
            ->severity('confirmed')
            ->headline(__('“:title” is out today', ['title' => implode(', ', $titles)]))
            ->lede(self::greet($this->buyerName, __('the book you pre-ordered has been released. Your downloads are below.')));

        if ($downloads !== []) {
            $letter->items($downloads, __('Your downloads'));
        }

        $letter->facts([
            __('Order') => $this->orderNumber,
            __('License key') => $this->licenseKey,
        ]);

        if ($this->receiptUrl) {
            $letter->cta(__('View your order'), $this->receiptUrl);
        } elseif ($downloads !== []) {
            $letter->cta(__('Download your book'), $downloads[0]['url']);
        }

        if ($this->linksExpireAt) {
            $letter->callout('info', __('The links work until :date; write to us if you need them again after that.', ['date' => $this->linksExpireAt->translatedFormat('F j, Y')]));
        }

        return $letter->reason(__('You received this because you pre-ordered this book with this address.'));
    }
}
