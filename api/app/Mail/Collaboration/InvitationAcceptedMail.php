<?php

namespace App\Mail\Collaboration;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\BookMember;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Tells a book's owner that somebody they invited has joined. */
class InvitationAcceptedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'collaboration';

    public function __construct(public BookMember $member) {}

    public function account(): ?User
    {
        return $this->member->book->user;
    }

    public function letter(): Letter
    {
        $book = $this->member->book;
        $who = $this->member->user?->name ?? $this->member->email;
        $role = BookInvitationMail::roleName($this->member->role);

        return Letter::make(__(':name accepted your invitation to “:title”', ['name' => $who, 'title' => $book->title]))
            ->preheader(__(':name can now open “:title” as :role.', ['name' => $who, 'title' => $book->title, 'role' => mb_strtolower($role)]))
            ->subtitle(__('Invitation accepted'))
            ->eyebrow(__('New collaborator'))
            ->severity('confirmed')
            ->headline(__(':name joined your book', ['name' => $who]))
            ->lede(self::greet($book->user?->name, __(':name accepted your invitation and can now open the book. :what', [
                'name' => $who,
                'what' => BookInvitationMail::roleDescription($this->member->role),
            ])))
            ->book($book)
            ->facts([
                __('Collaborator') => $this->member->user
                    ? "{$this->member->user->name} ({$this->member->email})"
                    : $this->member->email,
                __('Role') => $role,
                __('Joined') => ($this->member->accepted_at ?? now())->translatedFormat('F j, Y'),
            ])
            ->cta(__('Open the book'), route('books.overview', $book))
            ->reason(__('You received this because you invited :email to “:title”.', ['email' => $this->member->email, 'title' => $book->title]));
    }
}
