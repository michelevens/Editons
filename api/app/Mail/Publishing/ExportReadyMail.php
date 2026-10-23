<?php

namespace App\Mail\Publishing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Mail\Support\Money;
use App\Models\BookExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A generated file (EPUB, PDF, Word…) finished building. */
class ExportReadyMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'publishing';

    public function __construct(public BookExport $export, public User $recipient) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $book = $this->export->book;
        $format = $this->export->label();

        return Letter::make(__('Your :format of “:title” is ready', ['format' => $format, 'title' => $book->title]))
            ->preheader(__(':format, :size. Download it from the studio whenever you are ready.', [
                'format' => $format,
                'size' => Money::bytes($this->export->byte_size) ?? __('ready'),
            ]))
            ->subtitle(__('Export ready'))
            ->eyebrow(__('Export · :format', ['format' => $format]))
            ->severity('confirmed')
            ->headline(__('Your :format is ready', ['format' => $format]))
            ->lede(self::greet($this->recipient->name, __('the :format of “:title” has finished building and is ready to download.', [
                'format' => $format,
                'title' => $book->title,
            ])))
            ->book($book)
            ->facts([
                __('Format') => $format.' (.'.$this->export->extension().')',
                __('File size') => Money::bytes($this->export->byte_size),
                __('Words') => $this->export->word_count ? number_format($this->export->word_count) : null,
                __('Built') => ($this->export->updated_at ?? now())->translatedFormat('F j, Y \a\t g:i A'),
            ])
            ->cta(__('Download :format', ['format' => $format]), route('publishing.download', ['book' => $book, 'export' => $this->export]))
            ->secondary(__('See every export of this book'), route('books.edit', ['book' => $book, 'pane' => 'export']))
            ->callout('info', __('Downloads need you to be signed in, so this link is safe to keep: it will not open for anybody who is not on the book.'))
            ->reason(__('You received this because you started this export. You can switch export emails off in your preferences.'));
    }
}
