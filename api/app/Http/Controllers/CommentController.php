<?php

namespace App\Http\Controllers;

use App\Inbox\Inbox;
use App\Mail\Notify;
use App\Models\Book;
use App\Models\Comment;
use App\Models\ManuscriptSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comments on the manuscript.
 *
 * Commenting is gated on `view`, not `update`. A reviewer who cannot edit the
 * book is exactly the person you invited to comment on it, and requiring
 * write access would make the reviewer role useless.
 *
 * Resolving is different: it closes a conversation somebody else started, so
 * it belongs to people who can act on it — the author, an editor, or whoever
 * raised it.
 */
class CommentController extends Controller
{
    /** Every thread in a book, newest first. */
    public function index(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($book));
    }

    public function store(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('view', $section);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:comments,id'],
        ]);

        $parent = isset($data['parent_id'])
            ? Comment::find($data['parent_id'])
            : null;

        // A reply must belong to a thread in this same section, or a comment
        // could be grafted onto another book's conversation.
        if ($parent) {
            abort_unless(
                $parent->manuscript_section_id === $section->id && ! $parent->isReply(),
                422,
                'That is not a thread on this chapter.',
            );
        }

        $comment = $section->comments()->create([
            'parent_id' => $parent?->id,
            'user_id' => $request->user()->id,
            'author_name' => $request->user()->name,
            'body' => $data['body'],
            // Replies carry no anchor; the thread owns it.
            'excerpt' => $parent ? null : ($data['excerpt'] ?? null),
        ]);

        // @mentions, and replies to everybody else in the thread.
        Notify::commentPosted($comment);
        Inbox::commentPosted($comment);

        return response()->json($this->payload($section->book), 201);
    }

    public function resolve(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('view', $comment->section);

        abort_if($comment->isReply(), 422, 'Resolve the thread, not a reply.');

        // Whoever can change the book, plus whoever raised it. A reviewer
        // closing their own question is reasonable; closing someone else's is
        // not theirs to do.
        abort_unless(
            $request->user()->can('update', $comment->section->book)
                || $comment->user_id === $request->user()->id,
            403,
            'Only the author, an editor, or whoever raised this can resolve it.',
        );

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Comment::STATUSES)],
        ]);

        $resolving = $data['status'] === 'resolved';

        // forceFill rather than widening $fillable: who resolved a thread and
        // when is decided by the server, and a client should never be able to
        // claim either.
        $comment->forceFill([
            'status' => $data['status'],
            'resolved_by' => $resolving ? $request->user()->id : null,
            'resolved_at' => $resolving ? now() : null,
        ])->save();

        return response()->json($this->payload($comment->section->book));
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('view', $comment->section);

        // Only the person who wrote it, or the book's owner. An editor
        // deleting a reviewer's objection would be the wrong default.
        abort_unless(
            $comment->user_id === $request->user()->id
                || $request->user()->can('manage', $comment->section->book),
            403,
            'You can only delete your own comments.',
        );

        $book = $comment->section->book;
        $comment->delete();

        return response()->json($this->payload($book));
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        $sections = $book->sections()->get()->keyBy('id');

        $threads = Comment::whereIn('manuscript_section_id', $sections->keys())
            ->whereNull('parent_id')
            ->with(['replies', 'author:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return [
            'threads' => $threads
                ->map(function (Comment $thread) use ($sections) {
                    $section = $sections[$thread->manuscript_section_id];

                    return [
                        'id' => $thread->id,
                        'section_id' => $section->id,
                        'section_title' => $section->title,
                        'author_name' => $thread->author_name,
                        'body' => $thread->body,
                        'excerpt' => $thread->excerpt,
                        'status' => $thread->status,
                        'created_at' => $thread->created_at->diffForHumans(),
                        // Computed, never stored: the manuscript moves after a
                        // comment is made, and a thread pointing at deleted
                        // text should say so.
                        'detached' => $thread->isDetached($section->plainText()),
                        'replies' => $thread->replies->map(fn (Comment $reply) => [
                            'id' => $reply->id,
                            'author_name' => $reply->author_name,
                            'body' => $reply->body,
                            'created_at' => $reply->created_at->diffForHumans(),
                        ])->all(),
                    ];
                })
                ->all(),
            'counts' => [
                'open' => $threads->where('status', 'open')->count(),
                'resolved' => $threads->where('status', 'resolved')->count(),
            ],
        ];
    }
}
