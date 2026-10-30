<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Platform\Support\SupportDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Help: an author's requests to Écrivain support, and the answers.
 *
 * Every query is scoped to the signed-in account, and a ticket that is not
 * theirs is not found. Staff's internal notes never leave the console.
 */
class SupportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Support/Index', [
            'tickets' => SupportTicket::where('user_id', $user->id)
                ->latest('last_message_at')
                ->limit(50)
                ->get()
                ->map(fn (SupportTicket $t) => [
                    'id' => $t->id,
                    'subject' => $t->subject,
                    'status' => $t->status,
                    'last_message_at' => $t->last_message_at?->toIso8601String(),
                ]),
            'books' => $user->books()->where('status', '!=', 'archived')->orderBy('title')->get(['id', 'title']),
            'book_id' => $request->integer('book') ?: null,
        ]);
    }

    public function store(Request $request, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
            'book_id' => ['nullable', 'integer'],
            'context' => ['nullable', 'array'],
            'context.viewport' => ['nullable', 'string', 'max:40'],
            'context.page' => ['nullable', 'string', 'max:300'],
            'context.last_error' => ['nullable', 'string', 'max:2000'],
        ]);

        $book = empty($data['book_id']) ? null : Book::find($data['book_id']);

        $ticket = $desk->open(
            $request->user(),
            $data['subject'],
            $data['body'],
            $book,
            $data['context'] ?? [],
            $request->userAgent(),
        );

        return redirect()->route('support.show', $ticket)->with('platform_notice', __('Sent. Ecrivain support will reply here and by email.'));
    }

    public function show(Request $request, SupportTicket $ticket): Response
    {
        $ticket = $this->own($request, $ticket);

        return Inertia::render('Support/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'book' => $ticket->book ? ['id' => $ticket->book->id, 'title' => $ticket->book->title] : null,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'messages' => $ticket->publicMessages()->get()->map(fn (SupportMessage $m) => [
                    'id' => $m->id,
                    'body' => $m->body,
                    'from_staff' => $m->from_staff,
                    // Staff are "Ecrivain support" to an author, not a name.
                    'author' => $m->from_staff ? config('platform.staff_name') : 'You',
                    'at' => $m->created_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportDesk $desk): RedirectResponse
    {
        $ticket = $this->own($request, $ticket);

        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000']]);
        $desk->authorReply($ticket, $request->user(), $data['body']);

        return back()->with('platform_notice', __('Sent.'));
    }

    private function own(Request $request, SupportTicket $ticket): SupportTicket
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);

        return $ticket;
    }
}
