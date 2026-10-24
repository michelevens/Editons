<?php

namespace App\Http\Controllers;

use App\Models\InboxItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account inbox: comments, mentions, review-board moves, people joining,
 * finished exports and AI reviews, across every book the person can open.
 *
 * A page of the account (like Billing and Sales), not a pane of the editor:
 * it spans books, and an editor pane needs one book to be open in.
 *
 * Every query goes through InboxItem::scopeFor, so a person only ever sees
 * their own rows, and only about books they can still open.
 */
class InboxController extends Controller
{
    private const VIEWS = ['inbox', 'snoozed', 'done'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'inbox';

        $query = InboxItem::for($user)->with('book:id,title');
        $query = match ($view) {
            'snoozed' => $query->snoozed()->orderBy('snoozed_until'),
            'done' => $query->whereNotNull('done_at')->latest('done_at'),
            default => $query->waiting()->latest('occurred_at')->latest('id'),
        };

        return Inertia::render('Inbox', [
            'view' => $view,
            'items' => $query->limit(100)->get()->map(fn (InboxItem $item) => self::present($item))->all(),
            'counts' => self::counts($user),
        ]);
    }

    /** Read, unread, done, back, snooze until tomorrow, wake. */
    public function update(Request $request, InboxItem $item): JsonResponse
    {
        $item = $this->own($request->user(), $item);

        $data = $request->validate([
            'action' => ['required', 'in:read,unread,done,undone,snooze,unsnooze'],
        ]);

        $item->update(match ($data['action']) {
            'read' => ['read_at' => $item->read_at ?? now()],
            'unread' => ['read_at' => null],
            // Done is also read: it is out of the way because it was dealt with.
            'done' => ['done_at' => now(), 'read_at' => $item->read_at ?? now()],
            'undone' => ['done_at' => null],
            'snooze' => ['snoozed_until' => self::tomorrow()],
            'unsnooze' => ['snoozed_until' => null],
        });

        return response()->json([
            'item' => self::present($item->fresh('book:id,title')),
            'counts' => self::counts($request->user()),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        InboxItem::for($request->user())->waiting()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['counts' => self::counts($request->user())]);
    }

    /**
     * Open what an item is about: marked read, then the screen it names.
     * A book the person has since lost access to is not opened.
     */
    public function open(Request $request, InboxItem $item): RedirectResponse
    {
        $item = $this->own($request->user(), $item);
        $item->update(['read_at' => $item->read_at ?? now()]);

        return redirect()->to(self::destination($item));
    }

    /** The unread count, for the badge on every signed-in page. */
    public static function unread(User $user): int
    {
        return InboxItem::for($user)->waiting()->whereNull('read_at')->count();
    }

    /** @return array{unread: int, inbox: int, snoozed: int} */
    private static function counts(User $user): array
    {
        return [
            'unread' => self::unread($user),
            'inbox' => InboxItem::for($user)->waiting()->count(),
            'snoozed' => InboxItem::for($user)->snoozed()->count(),
        ];
    }

    /** Somebody else's item, or one about a book now closed to them, is not found. */
    private function own(User $user, InboxItem $item): InboxItem
    {
        abort_unless(InboxItem::for($user)->whereKey($item->id)->exists(), 404);

        return $item;
    }

    /** Tomorrow morning: the next working start, in the app's time zone. */
    private static function tomorrow(): CarbonInterface
    {
        return now()->addDay()->setTime(8, 0);
    }

    private static function destination(InboxItem $item): string
    {
        // A reply from support opens the conversation.
        if ($ticket = $item->target['support_ticket_id'] ?? null) {
            return route('support.show', $ticket);
        }

        // An announcement's link, when it stays on this app: the inbox is
        // never a redirector to another site.
        $url = in_array($item->kind, ['announcement', 'partner'], true) ? ($item->target['url'] ?? null) : null;

        if (is_string($url) && parse_url($url, PHP_URL_HOST) === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return $url;
        }

        if (! $item->book_id) {
            return route('inbox.index');
        }

        $target = $item->target ?? [];
        $query = array_filter([
            'pane' => $target['pane'] ?? null,
            'section' => $target['section_id'] ?? null,
        ]);

        return route('books.edit', $item->book_id).($query ? '?'.http_build_query($query) : '');
    }

    /** @return array<string, mixed> */
    private static function present(InboxItem $item): array
    {
        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'body' => $item->body,
            'book' => $item->book ? ['id' => $item->book->id, 'title' => $item->book->title] : null,
            'at' => $item->occurred_at->toIso8601String(),
            'ago' => $item->occurred_at->diffForHumans(short: true),
            'read' => $item->read_at !== null,
            'done' => $item->done_at !== null,
            'snoozed_until' => $item->snoozed_until?->isFuture() ? $item->snoozed_until->toIso8601String() : null,
            'url' => route('inbox.open', $item),
        ];
    }
}
