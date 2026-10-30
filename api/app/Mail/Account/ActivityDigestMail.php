<?php

namespace App\Mail\Account;

use App\Mail\EcrivainMail;
use App\Mail\EmailPreferences;
use App\Mail\Support\Letter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The daily or weekly digest: every email the person chose to receive
 * gathered rather than one at a time (App\Mail\HeldEmails), as one list
 * grouped by kind, each line linking where its own email would have.
 *
 * Filed under 'account' (always sent): the only way to receive it is to
 * have asked for it, and it is switched off by setting those categories
 * back to instant or off, which the footer link leads to.
 */
class ActivityDigestMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'account';

    /**
     * @param  'daily'|'weekly'  $frequency
     * @param  list<array{category: string, subject: string, url: ?string, book: ?string, at: string}>  $items
     */
    public function __construct(
        public User $user,
        public string $frequency,
        public array $items,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $count = count($this->items);
        $weekly = $this->frequency === 'weekly';

        $letter = Letter::make($weekly
                ? trans_choice('{1} Your weekly digest: :count update|[2,*] Your weekly digest: :count updates', $count, ['count' => $count])
                : trans_choice('{1} Your daily digest: :count update|[2,*] Your daily digest: :count updates', $count, ['count' => $count]))
            ->preheader(__('Everything that happened on your books, in one email.'))
            ->subtitle($weekly ? __('Your weekly digest') : __('Your daily digest'))
            ->severity('info')
            ->headline($weekly ? __('This week on your books') : __('Today on your books'))
            ->lede(self::greet($this->user->name, __('here is what you asked us to gather instead of emailing one at a time.')));

        $groups = [];

        foreach ($this->items as $item) {
            $groups[$item['category']][] = $item;
        }

        foreach ($groups as $category => $items) {
            $letter->items(
                array_map(fn (array $item) => array_filter([
                    'title' => $item['subject'],
                    'url' => $item['url'],
                    'detail' => $item['book'],
                ], fn ($v) => $v !== null), $items),
                __(EmailPreferences::CATEGORIES[$category]['label'] ?? 'Updates'),
            );
        }

        return $letter
            ->cta(__('Open your books'), route('books.index'))
            ->reason($weekly
                ? __('You get this once a week because you chose a weekly digest. Change it in your email preferences.')
                : __('You get this once a day because you chose a daily digest. Change it in your email preferences.'));
    }
}
