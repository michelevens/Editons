<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Sent once, when somebody creates an account. */
class WelcomeMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    public function __construct(public User $user) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $plan = $this->user->planLimits();

        return Letter::make(__('Welcome to :brand', ['brand' => $brand]))
            ->preheader(__('Your studio is ready. Start a book from one idea, or bring in a manuscript you already have.'))
            ->subtitle(__('Welcome'))
            ->eyebrow(__('Your account is ready'))
            ->severity('action')
            ->headline(__('Your writing studio is ready'))
            ->lede(self::greet($this->user->name, __('welcome to :brand. Everything you need to write, edit and publish a book is in one place.', ['brand' => $brand])))
            ->items([
                [
                    'title' => __('Start from an idea'),
                    'detail' => __('Quick Start asks one question and opens your first chapter.'),
                ],
                [
                    'title' => __('Bring in a manuscript'),
                    'detail' => __('Import a Word or Markdown file; chapters come across as chapters.'),
                ],
                [
                    'title' => __('Ask an editor'),
                    'detail' => __('Once there are words on the page, run an editorial review on any chapter.'),
                ],
            ], __('Three ways to begin'))
            ->facts([
                __('Signed in as') => $this->user->email,
                __('Plan') => $plan['name'] ?? __('Free'),
                __('AI credits') => ($plan['credits'] ?? 0) > 0
                    ? __(':n a month', ['n' => number_format($plan['credits'])])
                    : null,
            ])
            ->cta(__('Start your first book'), route('books.quick'))
            ->callout('info', __('AI suggestions are never applied without you. Every change, yours or an editor\'s, is kept in version history so you can always go back.'), __('Your words stay in your hands'))
            ->reason(__('You received this because you signed up for :brand with this address.', ['brand' => $brand]));
    }
}
