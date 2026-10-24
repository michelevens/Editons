<?php

namespace App\Mail\Growth;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\User;

/**
 * A lifecycle email (App\Growth\Lifecycle): three days in without a book,
 * two weeks without writing. Each goes to an account once, ever.
 *
 * Marketing, category "tips": unsubscribe link and header, and skipped for
 * anybody who switched tips off. Sent from the hourly command, not queued
 * again.
 */
class LifecycleMail extends EcrivainMail
{
    public const CATEGORY = 'tips';

    public function __construct(
        public User $user,
        public string $headline,
        public string $body,
        public ?string $ctaLabel = null,
        public bool $test = false,
    ) {}

    public function account(): ?User
    {
        return $this->test ? null : $this->user;
    }

    public function letter(): Letter
    {
        $paragraphs = BroadcastMail::paragraphs($this->body);

        $letter = Letter::make(($this->test ? '[Test] ' : '').$this->headline)
            ->preheader(mb_substr($paragraphs[0] ?? '', 0, 140))
            ->subtitle(__('Getting started'))
            ->severity('info')
            ->headline($this->headline)
            // The owner's copy is left as written, so the greeting stands on
            // its own line rather than being joined to its first sentence.
            ->lede(self::firstName($this->user->name) ? __('Hi :name,', ['name' => self::firstName($this->user->name)]) : null);

        foreach ($paragraphs as $paragraph) {
            $letter->paragraph($paragraph);
        }

        return $letter
            ->cta($this->ctaLabel ?: __('Open your books'), route('books.index'))
            ->reason(__('You get a few tips while you are getting started. Turn them off in your email preferences, or with the link below.'));
    }
}
