<?php

namespace App\Mail\Writing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Last week, in writing.
 *
 * Built by App\Mail\Support\WeeklyProgress from version history — words
 * actually added, not keystrokes — and sent by `php artisan
 * emails:weekly-digest`. Not scheduled by default.
 *
 * The one email that counts as marketing: it is not a consequence of
 * anything the reader just did, so it carries an unsubscribe link and a
 * List-Unsubscribe header.
 */
class WeeklyDigestMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'digest';

    /**
     * @param  array{
     *     words: int,
     *     active_days: int,
     *     chapters_touched: int,
     *     open_findings: int,
     *     open_comments: int,
     *     books: list<array{id: int, title: string, words_added: int, total_words: int, target_words: ?int, url: string}>
     * }  $summary
     */
    public function __construct(
        public User $user,
        public CarbonInterface $periodStart,
        public CarbonInterface $periodEnd,
        public array $summary,
    ) {}

    public function account(): ?User
    {
        return $this->user;
    }

    public function letter(): Letter
    {
        $words = (int) $this->summary['words'];
        $days = (int) $this->summary['active_days'];
        $range = $this->periodStart->translatedFormat(__('M j')).' – '.$this->periodEnd->translatedFormat(__('M j'));

        $letter = Letter::make($words > 0
                ? __('Your week in writing: :n words', ['n' => number_format($words)])
                : __('Your week in writing, :range', ['range' => $range]))
            ->preheader($words > 0
                ? trans_choice('{1} :words words across :count day of writing. Here is where your books stand.|[2,*] :words words across :count days of writing. Here is where your books stand.', max(1, $days), ['words' => number_format($words), 'count' => max(1, $days)])
                : __('A quiet week. Here is where your books stand, ready for when you are.'))
            ->subtitle(__('Your week in writing'))
            ->eyebrow($range)
            ->severity('info')
            ->headline($words > 0
                ? __(':n new words last week', ['n' => number_format($words)])
                : __('A quiet week'))
            ->lede(self::greet($this->user->name, $words > 0
                ? __('here is what you wrote last week, and where each book stands.')
                : __('nothing new went into your books last week. That happens; here is where they stand for when you pick them up again.')))
            ->stats([
                ['label' => __('Words added'), 'value' => number_format($words), 'tone' => $words > 0 ? 'success' : 'default'],
                ['label' => __('Days writing'), 'value' => $days.' / 7'],
                ['label' => __('Chapters touched'), 'value' => (int) $this->summary['chapters_touched']],
            ])
            ->items(
                array_map(fn (array $book) => [
                    'title' => $book['title'],
                    'url' => $book['url'],
                    'detail' => $book['target_words']
                        ? __(':total of :target words · :pct%', [
                            'total' => number_format($book['total_words']),
                            'target' => number_format($book['target_words']),
                            'pct' => (int) min(100, round($book['total_words'] / max(1, $book['target_words']) * 100)),
                        ])
                        : __(':total words so far', ['total' => number_format($book['total_words'])]),
                    'badge' => $book['words_added'] !== 0
                        ? ($book['words_added'] > 0 ? '+' : '−').number_format(abs($book['words_added']))
                        : null,
                    'tone' => $book['words_added'] > 0 ? 'success' : 'neutral',
                ], $this->summary['books']),
                __('Your books'),
            );

        $waiting = array_filter([
            $this->summary['open_findings'] > 0
                ? trans_choice('{1} :count editorial finding|[2,*] :count editorial findings', $this->summary['open_findings'], ['count' => $this->summary['open_findings']])
                : null,
            $this->summary['open_comments'] > 0
                ? trans_choice('{1} :count open comment thread|[2,*] :count open comment threads', $this->summary['open_comments'], ['count' => $this->summary['open_comments']])
                : null,
        ]);

        if ($waiting !== []) {
            $letter->paragraph(__('Waiting for you: :things.', ['things' => implode(__(' and '), $waiting)]));
        }

        if ($this->user->daily_word_goal) {
            $letter->paragraph(__('Your daily goal is :goal words. A goal, not a quota.', ['goal' => number_format($this->user->daily_word_goal)]));
        }

        return $letter
            ->cta(__('Open your books'), route('books.index'))
            ->reason(__('You get this summary once a week. Turn it off in your email preferences, or with the link below.'));
    }
}
