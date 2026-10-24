<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CannedReply;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\Ai\AiControls;
use App\Platform\Support\SupportDesk;
use App\Platform\Support\SupportDrafter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The support inbox: requests from authors, replies, internal notes,
 * assignment, and the owner's canned replies.
 *
 * Staff read the ticket and the context it arrived with (plan, browser, the
 * last error), never the author's manuscript: that is still View as, with
 * its reason and its record.
 */
class SupportController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');
        $mine = $request->boolean('mine');

        $tickets = SupportTicket::query()
            ->with(['user:id,name,email', 'assignee:id,name'])
            ->withCount(['messages' => fn ($q) => $q->where('internal', false)])
            ->when(in_array($status, SupportTicket::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($mine, fn ($q) => $q->where('assignee_id', $request->user()->id))
            ->orderBy('last_message_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Admin/Support/Index', [
            'status' => $status,
            'mine' => $mine,
            'counts' => SupportTicket::query()
                ->selectRaw('status, count(*) as n')
                ->groupBy('status')
                ->pluck('n', 'status'),
            'tickets' => $tickets->through(fn (SupportTicket $t) => [
                'id' => $t->id,
                'subject' => $t->subject,
                'status' => $t->status,
                'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name, 'email' => $t->user->email] : null,
                'assignee' => $t->assignee?->name,
                'messages' => $t->messages_count,
                'created_at' => $t->created_at?->toIso8601String(),
                'last_message_at' => $t->last_message_at?->toIso8601String(),
            ]),
            'canned' => CannedReply::orderBy('title')->get(['id', 'title', 'body']),
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    public function show(Request $request, SupportTicket $ticket, AiControls $controls): Response
    {
        $ticket->load(['user:id,name,email,plan', 'book:id,title', 'assignee:id,name', 'messages.author:id,name']);

        return Inertia::render('Admin/Support/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'assignee_id' => $ticket->assignee_id,
                'user' => $ticket->user ? ['id' => $ticket->user->id, 'name' => $ticket->user->name, 'email' => $ticket->user->email] : null,
                'book' => $ticket->book ? ['id' => $ticket->book->id, 'title' => $ticket->book->title] : null,
                'context' => $ticket->context ?? [],
                'created_at' => $ticket->created_at?->toIso8601String(),
                'last_message_at' => $ticket->last_message_at?->toIso8601String(),
                'messages' => $ticket->messages->map(fn (SupportMessage $m) => [
                    'id' => $m->id,
                    'body' => $m->body,
                    'from_staff' => $m->from_staff,
                    'internal' => $m->internal,
                    'ai_drafted' => $m->ai_drafted,
                    'author' => $m->author?->name,
                    'at' => $m->created_at?->toIso8601String(),
                ]),
            ],
            'staff' => User::whereNotNull('platform_role')->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'canned' => CannedReply::orderBy('title')->get(['id', 'title', 'body']),
            // The draft button exists only while the switch allows it and a
            // drafter is configured; otherwise it is not shown at all.
            'can_draft' => SupportDrafter::available() && $controls->refusal('support_draft') === null,
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'internal' => ['sometimes', 'boolean'],
            'ai_drafted' => ['sometimes', 'boolean'],
        ]);

        $internal = (bool) ($data['internal'] ?? false);
        $desk->staffReply($ticket, $request->user(), $data['body'], $internal, (bool) ($data['ai_drafted'] ?? false));

        return back()->with('platform_notice', $internal ? 'Note added.' : 'Reply sent to '.$ticket->user?->email.'.');
    }

    public function update(Request $request, SupportTicket $ticket, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $desk->update($ticket, $request->user(), $data);

        return back()->with('platform_notice', 'Ticket updated.');
    }

    public function draft(Request $request, SupportTicket $ticket, SupportDrafter $drafter, AiControls $controls): JsonResponse
    {
        abort_unless(SupportDrafter::available(), 404);
        $controls->assertAllowed('support_draft');

        try {
            return response()->json(['draft' => $drafter->draft($ticket, $request->user())]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Canned replies (owner) ──────────────────────────────────────────

    public function storeCanned(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        AdminAudit::transaction(function () use ($data, $request) {
            $canned = CannedReply::create($data + ['created_by' => $request->user()->id]);
            AdminAudit::record('support.canned_created', $canned, null, ['title' => $canned->title]);
        });

        return back()->with('platform_notice', 'Canned reply saved.');
    }

    public function updateCanned(Request $request, CannedReply $canned): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        AdminAudit::transaction(function () use ($data, $canned) {
            [$before, $after] = AdminAudit::diff($canned->only(['title', 'body']), $data);
            $canned->update($data);
            AdminAudit::record('support.canned_updated', $canned, $before, $after);
        });

        return back()->with('platform_notice', 'Canned reply updated.');
    }

    public function destroyCanned(CannedReply $canned): RedirectResponse
    {
        AdminAudit::transaction(function () use ($canned) {
            AdminAudit::record('support.canned_deleted', $canned, ['title' => $canned->title], null);
            $canned->delete();
        });

        return back()->with('platform_notice', 'Canned reply deleted.');
    }
}
