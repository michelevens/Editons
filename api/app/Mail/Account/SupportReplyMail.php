<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Écrivain support answered a request. Account mail: somebody who asked
 * for help is always told the answer, whatever their email preferences.
 */
class SupportReplyMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(public SupportTicket $ticket, public SupportMessage $reply) {}

    public function account(): ?User
    {
        return $this->ticket->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $staff = (string) config('platform.staff_name');

        return Letter::make(__('Re: :subject', ['subject' => $this->ticket->subject]))
            ->preheader(Str::limit($this->reply->body, 110))
            ->subtitle(__('Support'))
            ->eyebrow(__('Your request'))
            ->severity('action')
            ->headline(__(':staff replied', ['staff' => $staff]))
            ->lede(self::greet($this->ticket->user?->name, __('here is the answer to your request “:subject”.', ['subject' => $this->ticket->subject])))
            ->quote(Str::limit($this->reply->body, 4000), $staff)
            ->cta(__('Reply in :brand', ['brand' => $brand]), $this->ticket->exists
                ? route('support.show', $this->ticket)
                : route('support.index'))
            ->reason(__('You received this because you asked :brand support for help. Replies to this email reach support too.', ['brand' => $brand]));
    }
}
