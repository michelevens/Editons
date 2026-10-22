<?php

namespace App\Mail\Publishing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\BookExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** A generated file failed to build. */
class ExportFailedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'publishing';

    public function __construct(
        public BookExport $export,
        public User $recipient,
        public ?string $reason = null,
    ) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $book = $this->export->book;
        $format = $this->export->label();

        return Letter::make(__('We could not build your :format of “:title”', ['format' => $format, 'title' => $book->title]))
            ->preheader(__('Your manuscript is untouched. Try the export again from the studio.'))
            ->subtitle(__('Export failed'))
            ->eyebrow(__('Export · :format', ['format' => $format]))
            ->severity('urgent')
            ->headline(__('Your :format did not finish', ['format' => $format]))
            ->lede(self::greet($this->recipient->name, __('something went wrong while building the :format of “:title”. Your manuscript is untouched; only the file was not made.', [
                'format' => $format,
                'title' => $book->title,
            ])))
            ->facts([
                __('Book') => $book->title,
                __('Format') => $format,
                __('Started') => ($this->export->created_at ?? now())->translatedFormat('F j, Y \a\t g:i A'),
                __('What went wrong') => $this->reason,
            ])
            ->cta(__('Try again'), route('publishing.show', $book))
            ->callout('warning', __('Write to :support with the book\'s title and the format, and we will look at the file with you.', [
                'support' => config('mail.ecrivain.support_address'),
            ]), __('If it fails a second time'))
            ->reason(__('You received this because you started this export. You can switch export emails off in your preferences.'));
    }
}
