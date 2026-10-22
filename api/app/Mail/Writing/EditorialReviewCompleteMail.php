<?php

namespace App\Mail\Writing;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * An editorial agent finished reviewing a chapter.
 *
 * Findings are counted by severity and the most serious few are listed, so
 * the email answers "do I need to look at this now?" without being the
 * review. Nothing in the manuscript has changed, and the email says so.
 */
class EditorialReviewCompleteMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'writing';

    /** How many findings to list before "and N more". */
    public const LISTED = 5;

    public function __construct(public EditorialReview $review, public User $recipient) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $section = $this->review->section;
        $book = $section->book;
        $agent = $this->review->agent->label();
        $findings = $this->findings();
        $counts = self::countBySeverity($findings);
        $total = $findings->count();

        $letter = Letter::make(__('Your :agent review of “:chapter” is ready', ['agent' => $agent, 'chapter' => $section->title]))
            ->preheader($total === 0
                ? __('No issues found in “:chapter”.', ['chapter' => $section->title])
                : trans_choice('{1} :count finding to look at|[2,*] :count findings to look at', $total, ['count' => $total])
                    .($counts['critical'] > 0 ? ', '.trans_choice('{1} :count critical|[2,*] :count critical', $counts['critical'], ['count' => $counts['critical']]) : '')
                    .'.')
            ->subtitle(__('Editorial review'))
            ->eyebrow(__(':agent · :chapter', ['agent' => $agent, 'chapter' => $section->title]))
            ->severity($counts['critical'] > 0 ? 'heads' : ($total === 0 ? 'confirmed' : 'action'))
            ->headline($total === 0
                ? __('A clean bill of health')
                : __('Your review is ready'))
            ->lede(self::greet($this->recipient->name, $total === 0
                ? __('the :agent read “:chapter” and found nothing to flag.', ['agent' => $agent, 'chapter' => $section->title])
                : __('the :agent has read “:chapter” and left notes for you to go through.', ['agent' => $agent, 'chapter' => $section->title])))
            ->stats([
                ['label' => __('Critical'), 'value' => $counts['critical'], 'tone' => $counts['critical'] ? 'danger' : 'default'],
                ['label' => __('Warnings'), 'value' => $counts['warning'], 'tone' => $counts['warning'] ? 'warning' : 'default'],
                ['label' => __('Suggestions'), 'value' => $counts['suggestion']],
            ]);

        if ($total > 0) {
            $listed = $findings->take(self::LISTED);
            $letter->items(
                $listed->map(fn ($f) => [
                    'title' => $f['title'],
                    'detail' => $f['detail'] ? Str::limit($f['detail'], 160) : null,
                    'badge' => self::severityLabel($f['severity']),
                    'tone' => self::severityTone($f['severity']),
                ])->all(),
                $total > self::LISTED
                    ? __('Most important · :n more in the studio', ['n' => $total - self::LISTED])
                    : __('What the editor found'),
            );
        }

        return $letter
            ->facts([
                __('Book') => $book->title,
                __('Chapter') => $section->title,
                __('Credits used') => $this->review->agent->costLabel()
                    ? (string) (config('billing.costs.'.$this->review->agent->costLabel()) ?? '')
                    : null,
            ])
            ->cta(__('Review the findings'), route('books.edit', ['book' => $book, 'section' => $section->id]))
            ->callout('info', __('Nothing in your manuscript has changed. Each suggestion waits for you to accept or dismiss it, and anything you accept is saved in version history.'))
            ->reason(__('You received this because you asked for this review. You can switch editorial review emails off in your preferences.'));
    }

    /** @return Collection<int, array{severity: string, title: string, detail: ?string}> */
    private function findings(): Collection
    {
        $rows = $this->review->relationLoaded('findings')
            ? $this->review->findings
            : $this->review->findings()->get();

        return $rows
            ->map(fn (EditorialFinding $f) => ['severity' => $f->severity, 'title' => $f->title, 'detail' => $f->detail])
            ->sortBy(fn ($f) => array_search($f['severity'], EditorialFinding::SEVERITIES, true))
            ->values();
    }

    /** @return array{critical: int, warning: int, suggestion: int} */
    public static function countBySeverity(Collection $findings): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'suggestion' => 0];

        foreach ($findings as $finding) {
            $severity = is_array($finding) ? $finding['severity'] : $finding->severity;
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return $counts;
    }

    public static function severityLabel(string $severity): string
    {
        return match ($severity) {
            'critical' => __('Critical'),
            'warning' => __('Warning'),
            default => __('Suggestion'),
        };
    }

    public static function severityTone(string $severity): string
    {
        return match ($severity) {
            'critical' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };
    }
}
