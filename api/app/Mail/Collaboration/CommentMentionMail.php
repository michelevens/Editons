<?php

namespace App\Mail\Collaboration;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/** Somebody wrote @Name in a comment. */
class CommentMentionMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'collaboration';

    public function __construct(public Comment $comment, public User $recipient) {}

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $section = $this->comment->section;
        $book = $section->book;
        $author = $this->comment->author_name;
        $excerpt = $this->comment->parent_id
            ? $this->comment->parent?->excerpt
            : $this->comment->excerpt;

        return Letter::make(__(':author mentioned you in “:title”', ['author' => $author, 'title' => $book->title]))
            ->preheader(Str::limit($this->comment->body, 110))
            ->subtitle(__('You were mentioned'))
            ->eyebrow(__('Comment · :chapter', ['chapter' => $section->title]))
            ->severity('action')
            ->headline(__(':author mentioned you', ['author' => $author]))
            ->lede(self::greet($this->recipient->name, __(':author mentioned you in a comment on “:chapter” in “:title”.', [
                'author' => $author,
                'chapter' => $section->title,
                'title' => $book->title,
            ])))
            ->quote(Str::limit($this->comment->body, 1200), $author)
            ->facts([
                __('Book') => $book->title,
                __('Chapter') => $section->title,
                __('On the passage') => $excerpt ? '“'.Str::limit($excerpt, 140).'”' : null,
            ])
            ->cta(__('Reply in the studio'), route('books.edit', ['book' => $book, 'section' => $section->id]))
            ->reason(__('You received this because you are on “:title”. You can switch collaboration emails off in your preferences.', ['title' => $book->title]));
    }
}
