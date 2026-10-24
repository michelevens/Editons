<?php

namespace App\Mail\Growth;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;

/**
 * A broadcast from the console (App\Growth\Broadcasts), in the standard
 * template: the owner writes a subject and plain paragraphs; the layout,
 * footer and one-click unsubscribe are the same as every other email.
 *
 * Marketing: category "news", so it carries the unsubscribe link and the
 * List-Unsubscribe header, and Notify::send skips anybody who switched it
 * off. Not queued itself — the broadcast's own throttled jobs send it.
 *
 * A test send goes to the owner with "[Test]" in the subject and no
 * account, so nobody's preferences are touched by clicking its footer.
 */
class BroadcastMail extends EcrivainMail
{
    public const CATEGORY = 'news';

    public function __construct(
        public ?User $user,
        public string $headline,
        public string $body,
        public ?string $preheaderText = null,
        public ?string $ctaLabel = null,
        public ?string $ctaUrl = null,
        public bool $test = false,
    ) {}

    public function account(): ?User
    {
        return $this->test ? null : $this->user;
    }

    public function letter(): Letter
    {
        $letter = Letter::make(($this->test ? '[Test] ' : '').$this->headline)
            ->preheader($this->preheaderText ?: mb_substr(trim(strtok($this->body, "\n") ?: ''), 0, 140))
            ->subtitle(__('News from :brand', ['brand' => config('mail.ecrivain.brand')]))
            ->severity('info')
            ->headline($this->headline);

        foreach (self::paragraphs($this->body) as $paragraph) {
            $letter->paragraph($paragraph);
        }

        if ($this->ctaLabel && $this->ctaUrl) {
            $letter->cta($this->ctaLabel, $this->ctaUrl);
        }

        if ($this->test) {
            $letter->callout('info', __('This is a test send to you. Nobody else received it.'));
        }

        return $letter->reason(__('You get occasional news from :brand. Turn it off in your email preferences, or with the link below.', ['brand' => config('mail.ecrivain.brand')]));
    }

    /**
     * Paragraphs from plain text: a blank line separates them. Text only —
     * the layout escapes it, so nothing the owner types becomes markup.
     *
     * @return list<string>
     */
    public static function paragraphs(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/', str_replace("\r\n", "\n", $body)) ?: []),
            fn (string $p) => $p !== '',
        ));
    }
}
