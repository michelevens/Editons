<?php

namespace App\Mail\Collaboration;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/** A reply in a comment thread the recipient started or took part in. */
class CommentReplyMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'collaboration';

    public function __construct(public Comment $reply, public User $recipient) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $thread = $this->reply->parent;
        $section = $this->reply->section;
        $book = $section->book;
        $author = $this->reply->author_name;
        $theirs = $thread && $thread->user_id === $this->recipient->id;

        return Letter::make($theirs
                ? __(':author replied to your comment', ['author' => $author])
                : __(':author replied in a thread on “:chapter”', ['author' => $author, 'chapter' => $section->title]))
            ->preheader(Str::limit($this->reply->body, 110))
            ->subtitle(__('New reply'))
            ->eyebrow(__('Comment · :chapter', ['chapter' => $section->title]))
            ->severity('action')
            ->headline($theirs
                ? __(':author replied to you', ['author' => $author])
                : __(':author added to the conversation', ['author' => $author]))
            ->lede(self::greet($this->recipient->name, __('there is a new reply on “:chapter” in “:title”.', [
                'chapter' => $section->title,
                'title' => $book->title,
            ])))
            ->quote(Str::limit($this->reply->body, 1200), $author)
            ->facts([
                __('Thread started by') => $thread?->author_name,
                __('Original comment') => $thread ? '“'.Str::limit($thread->body, 140).'”' : null,
                __('Replies so far') => $thread
                    ? (string) ($thread->relationLoaded('replies') ? $thread->replies->count() : $thread->replies()->count())
                    : null,
            ])
            ->cta(__('View the thread'), route('books.edit', ['book' => $book, 'section' => $section->id]))
            ->reason(__('You received this because you are part of this conversation. You can switch collaboration emails off in your preferences.'));
    }
}
