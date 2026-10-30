<?php

namespace App\Http\Controllers;

use App\Inbox\Inbox;
use App\Mail\Notify;
use App\Models\Book;
use App\Models\Comment;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $this->setStatus($comment, $data['status'], $request->user());

        return response()->json($this->payload($comment->section->book));
    }

    /**
     * Resolve or reopen many threads at once — the filtered list's "Resolve
     * all". Each thread gets exactly the check and the record a single
     * resolve gets: whoever can change the book, or whoever raised that
     * thread. Threads this person may not close are skipped and counted, not
     * a reason to refuse the rest; an id from another book refuses the lot.
     */
    public function bulkStatus(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'status' => ['required', 'in:'.implode(',', Comment::STATUSES)],
        ]);

        $ids = array_values(array_unique($data['ids']));
        $threads = Comment::whereIn('id', $ids)
            ->whereIn('manuscript_section_id', $book->sections()->select('id'))
            ->get();

        abort_if($threads->count() !== count($ids), 422, 'Some of those comments are not on this book.');

        $user = $request->user();
        $canEdit = $user->can('update', $book);
        $changed = 0;
        $skipped = 0;

        DB::transaction(function () use ($threads, $data, $user, $canEdit, &$changed, &$skipped) {
            foreach ($threads as $thread) {
                if ($thread->isReply() || ! ($canEdit || $thread->user_id === $user->id)) {
                    $skipped++;

                    continue;
                }

                // Already there: leave who resolved it, and when, alone.
                if ($thread->status === $data['status']) {
                    continue;
                }

                $this->setStatus($thread, $data['status'], $user);
                $changed++;
            }
        });

        return response()->json($this->payload($book) + ['changed' => $changed, 'skipped' => $skipped]);
    }

    private function setStatus(Comment $comment, string $status, User $user): void
    {
        $resolving = $status === 'resolved';

        // forceFill rather than widening $fillable: who resolved a thread and
        // when is decided by the server, and a client should never be able to
        // claim either.
        $comment->forceFill([
            'status' => $status,
            'resolved_by' => $resolving ? $user->id : null,
            'resolved_at' => $resolving ? now() : null,
        ])->save();
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

        // For the filters: whether a thread @mentions the viewer (in it or
        // any reply), and whether the viewer may resolve it — the same rule
        // resolve() enforces, so the bulk button never offers what the
        // server will refuse.
        $me = auth()->user();
        $roster = $me ? Notify::mentionable($book) : collect();
        $canEdit = $me?->can('update', $book) ?? false;
        $mentionsMe = fn (Comment $c) => $me !== null && collect(Notify::mentionedIn($c->body, $roster))->contains('id', $me->id);

        return [
            'threads' => $threads
                ->map(function (Comment $thread) use ($sections, $mentionsMe, $canEdit, $me) {
                    $section = $sections[$thread->manuscript_section_id];

                    return [
                        'id' => $thread->id,
                        'section_id' => $section->id,
                        'section_title' => $section->title,
                        'author_name' => $thread->author_name,
                        'user_id' => $thread->user_id,
                        'body' => $thread->body,
                        'excerpt' => $thread->excerpt,
                        'status' => $thread->status,
                        'mentions_me' => $mentionsMe($thread) || $thread->replies->contains($mentionsMe),
                        'can_resolve' => $canEdit || ($me !== null && $thread->user_id === $me->id),
                        'created_at' => $thread->created_at->diffForHumans(),
                        // Computed, never stored: the manuscript moves after a
                        // comment is made, and a thread pointing at deleted
                        // text should say so.
                        'detached' => $thread->isDetached($section->plainText(withSuggestions: true)),
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
