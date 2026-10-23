<?php

namespace App\Inbox;

use App\Mail\Notify;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\BookMember;
use App\Models\Comment;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\InboxItem;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * The account inbox: what happened on your books while you were elsewhere.
 *
 * Written where things happen, beside the email Notify sends, but not
 * gated by email preferences: somebody who turned off comment emails still
 * wants to find the comment. Every method says what happened and decides
 * here who hears about it, the way Notify does:
 *
 *  - nobody is told about their own action;
 *  - nobody is told about a book they cannot open (tenancy is checked when
 *    a row is written, and again by InboxItem::scopeFor when it is read);
 *  - recording never breaks the action that caused it.
 */
final class Inbox
{
    /**
     * One item for one person. With a $group, an item of that group still
     * in their inbox is brought back up to date instead of adding another.
     *
     * @param  array<string, mixed>  $target  where tapping it goes: pane, section_id, comment_id, export_id
     */
    public static function record(
        User $to,
        string $kind,
        string $title,
        ?Book $book = null,
        array $target = [],
        ?string $body = null,
        ?User $actor = null,
        ?string $group = null,
        bool $read = false,
    ): ?InboxItem {
        try {
            if ($actor && $actor->is($to) && ! $read) {
                return null;
            }

            if ($book && ! $to->can('view', $book)) {
                return null;
            }

            $values = [
                'kind' => $kind,
                'title' => Str::limit($title, 250),
                'body' => $body === null ? null : Str::limit($body, 600),
                'book_id' => $book?->id,
                'actor_id' => $actor?->id,
                'target' => $target ?: null,
                'occurred_at' => now(),
                'read_at' => $read ? now() : null,
                'snoozed_until' => null,
            ];

            if ($group !== null) {
                $existing = InboxItem::where('user_id', $to->id)
                    ->where('group_key', $group)
                    ->whereNull('done_at')
                    ->latest('id')
                    ->first();

                if ($existing) {
                    return tap($existing)->update($values);
                }
            }

            return InboxItem::create($values + ['user_id' => $to->id, 'group_key' => $group]);
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }

            report($e);

            return null;
        }
    }

    /**
     * A comment: whoever it @mentions, everybody else in the thread for a
     * reply, and the book's owner. One item each, the most specific winning.
     */
    public static function commentPosted(Comment $comment): void
    {
        $section = $comment->section;
        $book = $section->book;
        $actor = $comment->author;
        $who = $comment->author_name ?: 'Someone';
        $thread = $comment->parent_id ? $comment->parent : $comment;
        $target = ['pane' => 'comments', 'section_id' => $section->id, 'comment_id' => $thread->id];
        $told = [$comment->user_id => true];
        $chapter = '“'.$section->title.'”';

        foreach (Notify::mentioned($comment, $book) as $user) {
            if (! isset($told[$user->id])) {
                $told[$user->id] = true;
                self::record($user, 'mention', "{$who} mentioned you on {$chapter}", $book, $target, $comment->body, $actor);
            }
        }

        if ($comment->parent_id) {
            $participants = collect([$thread->user_id])
                ->merge($thread->replies()->pluck('user_id'))
                ->filter()
                ->unique();

            foreach (User::whereIn('id', $participants)->get() as $user) {
                if (! isset($told[$user->id])) {
                    $told[$user->id] = true;
                    self::record($user, 'reply', "{$who} replied on {$chapter}", $book, $target, $comment->body, $actor);
                }
            }
        }

        if ($book->user && ! isset($told[$book->user_id])) {
            self::record($book->user, 'comment', "{$who} commented on {$chapter}", $book, $target, $comment->body, $actor);
        }
    }

    /** A chapter moved on the review board: its owner and its reviewer hear. */
    public static function workflowMoved(ManuscriptSection $section, ?User $actor): void
    {
        $book = $section->book;
        $who = $actor?->name ?? 'Someone';
        $status = self::statusLabel($section->status);

        $people = User::whereIn('id', array_filter([$book->user_id, $section->reviewer_id]))->get();

        foreach ($people as $user) {
            self::record(
                $user,
                'workflow',
                "{$who} moved “{$section->title}” to {$status}",
                $book,
                ['pane' => 'workflow', 'section_id' => $section->id],
                actor: $actor,
                group: "workflow:{$section->id}",
            );
        }
    }

    public static function reviewerAssigned(ManuscriptSection $section, User $reviewer, ?User $actor): void
    {
        self::record(
            $reviewer,
            'assigned',
            ($actor?->name ?? 'Someone')." asked you to review “{$section->title}”",
            $section->book,
            ['pane' => 'workflow', 'section_id' => $section->id],
            actor: $actor,
        );
    }

    public static function invitationAccepted(BookMember $member): void
    {
        $book = $member->book;

        if (! $book?->user) {
            return;
        }

        $name = $member->user?->name ?? $member->email;

        self::record(
            $book->user,
            'team',
            "{$name} joined as ".($member->role === 'editor' ? 'an editor' : 'a '.$member->role),
            $book,
            ['pane' => 'team'],
            actor: $member->user,
        );
    }

    /** A queued PDF or EPUB finished, or did not: whoever asked for it. */
    public static function exportSettled(BookExport $export, bool $ready, ?string $reason = null): void
    {
        $book = $export->book;
        $recipient = ($export->user_id ? User::find($export->user_id) : null) ?? $book?->user;

        if (! $book || ! $recipient) {
            return;
        }

        $label = $export->label();

        self::record(
            $recipient,
            $ready ? 'export_ready' : 'export_failed',
            $ready ? "Your {$label} is ready to download" : "Your {$label} could not be made",
            $book,
            ['pane' => 'export', 'export_id' => $export->id],
            $ready ? null : $reason,
        );
    }

    /**
     * An AI review finished on a chapter. Every agent over one chapter is
     * one item, kept current, so a full review is one line, not seven.
     *
     * Whoever ran it saw the result arrive, so theirs is recorded as read;
     * the book's owner, when somebody else ran it, gets it as news.
     */
    public static function reviewFinished(EditorialReview $review): void
    {
        $section = $review->section;
        $book = $section?->book;

        if (! $book) {
            return;
        }

        $open = EditorialFinding::where('manuscript_section_id', $section->id)->where('status', 'open')->count();
        $failed = $review->status === 'failed';
        $title = $failed
            ? "The {$review->agent->label()} could not review “{$section->title}”"
            : "AI review of “{$section->title}”: {$open} open finding".($open === 1 ? '' : 's');
        $target = ['pane' => 'ai', 'section_id' => $section->id];
        $runner = $review->user_id ? User::find($review->user_id) : null;

        if ($runner) {
            self::record($runner, 'review', $title, $book, $target, $failed ? $review->error : null, $runner, "review:{$section->id}", read: true);
        }

        if ($book->user && ! $book->user->is($runner)) {
            self::record($book->user, 'review', $title, $book, $target, $failed ? $review->error : null, $runner, "review:{$section->id}");
        }
    }

    private static function statusLabel(?string $status): string
    {
        return match ($status) {
            'review' => 'In review',
            'revisions' => 'Revisions',
            'approved' => 'Approved',
            default => 'Draft',
        };
    }
}
