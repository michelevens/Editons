<?php

namespace App\Mail\Collaboration;

use App\Mail\EcrivainMail;
use App\Mail\Support\ContainsSecrets;
use App\Mail\Support\Letter;
use App\Models\BookMember;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An invitation to work on somebody's book.
 *
 * Addressed to an email, not an account: the person invited usually has
 * none yet. When they do, their collaboration preference is honoured.
 */
class BookInvitationMail extends EcrivainMail implements ContainsSecrets, ShouldQueue
{
    public const CATEGORY = 'collaboration';

    public function __construct(public BookMember $member) {}

    public function account(): ?User
    {
        return $this->member->user;
    }

    /**
     * Somebody invited by address has no language of their own yet; the
     * person who invited them is the best guess (they share a book).
     */
    public function fallbackLocale(): ?string
    {
        return ($this->member->inviter ?? $this->member->book?->user)?->preferredLocale();
    }

    public function acceptUrl(): string
    {
        return route('invitations.show', $this->member->invite_token);
    }

    public function letter(): Letter
    {
        $brand = config('mail.ecrivain.brand');
        $book = $this->member->book;
        $inviter = $this->member->inviter?->name ?? $book->user?->name ?? __('An author');
        $role = self::roleName($this->member->role);
        $expires = ($this->member->invited_at ?? now())->copy()->addDays(BookMember::INVITE_DAYS);

        return Letter::make(__(':inviter invited you to “:title”', ['inviter' => $inviter, 'title' => $book->title]))
            ->preheader(__('Join as :role. The invitation is open until :date.', [
                'role' => $role,
                'date' => $expires->translatedFormat('F j'),
            ]))
            ->subtitle(__('You are invited'))
            ->eyebrow(__('Invitation · :role', ['role' => $role]))
            ->severity('action')
            ->headline(__(':inviter would like you on their book', ['inviter' => $inviter]))
            ->lede(__(':inviter has invited you to join “:title” on :brand as :article :role. :what', [
                'inviter' => $inviter,
                'title' => $book->title,
                'brand' => $brand,
                'article' => $this->member->role === 'editor' ? __('an') : __('a'),
                'role' => mb_strtolower($role),
                'what' => self::roleDescription($this->member->role),
            ]))
            ->book($book)
            ->facts([
                __('Your role') => $role,
                __('Invited by') => $this->member->inviter
                    ? "{$this->member->inviter->name} ({$this->member->inviter->email})"
                    : $inviter,
                __('Sent to') => $this->member->email,
            ])
            ->cta(__('Accept invitation'), $this->acceptUrl(), showFallbackUrl: true)
            ->callout('info', __('It works only for :email. If you are new to :brand, you can create your account when you accept.', [
                'email' => $this->member->email,
                'brand' => $brand,
            ]), __('This invitation is open until :date', ['date' => $expires->translatedFormat('F j, Y')]))
            ->reason(__('You received this because :inviter invited this address. If you were not expecting it, you can ignore it.', ['inviter' => $inviter]));
    }

    public static function roleName(string $role): string
    {
        return match ($role) {
            'editor' => __('Editor'),
            'reviewer' => __('Reviewer'),
            'viewer' => __('Viewer'),
            default => ucfirst($role),
        };
    }

    public static function roleDescription(string $role): string
    {
        return match ($role) {
            'editor' => __('Editors can change the manuscript and leave comments.'),
            'reviewer' => __('Reviewers can read every chapter and comment, without changing the text.'),
            'viewer' => __('Viewers can read the book and download its proofs.'),
            default => '',
        };
    }
}
