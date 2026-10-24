<?php

namespace App\Http\Controllers\Admin\Business;

use App\Billing\PlanCatalog;
use App\Growth\Invitations\InvitationReport;
use App\Growth\Invitations\Invitations;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Controller;
use App\Mail\Growth\AuthorInvitationMail;
use App\Models\AuthorInvitation;
use App\Models\AuthorInvitationBatch;
use App\Models\MailLog;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invitations: staff invite people to join Écrivain, one at a time or as
 * a list, and follow them from invited to first export.
 *
 * Owner and support both invite; only the owner may include a plan, and
 * support's credits stop at its grant limit (Invitations::perks). Every
 * send, resend and revoke is audited with the change.
 */
class InvitationsController extends Controller
{
    private const EXPORT_LIMIT = 10000;

    public function __construct(private readonly Invitations $invitations) {}

    public function index(Request $request, InvitationReport $report, PlanCatalog $catalog): Response
    {
        $filters = InvitationReport::filters($request);
        $actor = $request->user();

        return Inertia::render('Admin/Business/Invitations', [
            'filters' => $filters,
            'counts' => $report->counts($filters),
            'funnel' => $report->funnel($filters),
            'invitations' => $report->query($filters)
                ->with(['inviter:id,name', 'acceptedUser:id,name', 'batch:id,campaign'])
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (AuthorInvitation $i) => self::row($i)),
            'batches' => AuthorInvitationBatch::latest('id')->limit(50)->get()->map(fn (AuthorInvitationBatch $b) => [
                'id' => $b->id,
                'label' => $b->label(),
            ]),
            'inviters' => User::whereIn('id', AuthorInvitation::query()->select('invited_by')->whereNotNull('invited_by')->distinct())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'statuses' => AuthorInvitation::STATUSES,
            'languages' => AuthorInvitation::LANGUAGES,
            // Paid plans an invitation may carry (hidden and legacy ones too).
            'plans' => collect($catalog->all())
                ->reject(fn ($p, $key) => $key === 'free')
                ->map(fn ($p, $key) => ['key' => $key, 'name' => $p['name']])
                ->values(),
            'limits' => [
                'can_plan' => $actor->isPlatformOwner(),
                'max_credits' => $actor->isPlatformOwner()
                    ? (int) config('platform.credits.owner_max_adjustment')
                    : (int) config('platform.credits.support_max_grant'),
                'max_plan_months' => (int) config('growth.invitations.max_plan_months'),
                'batch_max' => (int) config('growth.invitations.batch_max'),
                'default_days' => (int) config('growth.invitations.default_days'),
                'max_days' => (int) config('growth.invitations.max_days'),
                'max_resends' => (int) config('growth.invitations.max_resends'),
                'remaining_today' => $this->invitations->remainingToday($actor),
            ],
        ]);
    }

    public function show(AuthorInvitation $invitation, InvitationReport $report): Response
    {
        $invitation->load(['inviter:id,name,email', 'acceptedUser:id,name,email', 'batch']);

        return Inertia::render('Admin/Business/InvitationShow', [
            'invitation' => self::row($invitation) + [
                'note' => $invitation->note,
                'perks_applied' => $invitation->perks_applied,
                'perk_lines' => (new AuthorInvitationMail($invitation, 'preview'))->perkLines(),
            ],
            'timeline' => $report->timeline($invitation),
            'emails' => MailLog::where('mailable', AuthorInvitationMail::class)
                ->where('to', $invitation->email)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (MailLog $log) => [
                    'id' => $log->id,
                    'status' => $log->status,
                    'subject' => $log->subject,
                    'error' => $log->error,
                    'at' => $log->created_at?->toIso8601String(),
                ]),
            'max_resends' => (int) config('growth.invitations.max_resends'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            ...self::sharedRules(),
        ]);

        $invitation = $this->invitations->invite(self::shared($data) + [
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
        ], $request->user());

        return redirect()->route('admin.invitations.show', $invitation)
            ->with('platform_notice', 'Invitation sent to '.$invitation->email.'.');
    }

    /** Read a pasted list or a CSV file and say, row by row, what would happen. */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'text' => ['nullable', 'string', 'max:200000'],
            'file' => ['nullable', 'file', 'mimes:csv,txt', 'max:512'],
        ]);

        $text = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('text', '');

        if (! mb_check_encoding($text, 'UTF-8')) {
            // A spreadsheet saved as Windows-1252 rather than UTF-8.
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return response()->json($this->invitations->preview($text));
    }

    public function bulk(Request $request): RedirectResponse
    {
        $max = (int) config('growth.invitations.batch_max');

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', "max:{$max}"],
            'rows.*.email' => ['required', 'string', 'max:255'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.language' => ['nullable', 'string', Rule::in(array_keys(AuthorInvitation::LANGUAGES))],
            'source' => ['required', Rule::in(['paste', 'csv'])],
            ...self::sharedRules(),
        ]);

        $batch = $this->invitations->bulk($data['rows'], self::shared($data), $data['source'], $request->user());

        return redirect()->route('admin.invitations.index', ['batch' => $batch->id])
            ->with('platform_notice', "Sent {$batch->sent} invitation".($batch->sent === 1 ? '' : 's').($batch->skipped ? ", skipped {$batch->skipped}." : '.'));
    }

    public function resend(Request $request, AuthorInvitation $invitation): RedirectResponse
    {
        $this->invitations->resend($invitation, $request->user());

        return back()->with('platform_notice', 'Sent again to '.$invitation->email.', with a new link.');
    }

    public function revoke(Request $request, AuthorInvitation $invitation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        $this->invitations->revoke($invitation, $data['reason'], $request->user());

        return back()->with('platform_notice', 'Invitation revoked: the link no longer works.');
    }

    public function export(Request $request, InvitationReport $report): StreamedResponse
    {
        $filters = InvitationReport::filters($request);

        AdminAudit::transaction(fn () => AdminAudit::record('invitation.exported', after: ['filters' => $filters]));

        $query = $report->query($filters)->with(['inviter:id,email', 'batch:id,campaign'])->latest('id')->limit(self::EXPORT_LIMIT);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'email', 'name', 'language', 'status', 'invited_by', 'batch', 'campaign', 'plan', 'plan_months', 'credits', 'sent_at', 'resends', 'expires_at', 'accepted_at', 'accepted_user_id']);

            foreach ($query->get() as $i) {
                $perks = $i->perkList();
                fputcsv($out, array_map([AuditLogController::class, 'cell'], [
                    $i->id, $i->email, $i->name, $i->language, $i->state(),
                    $i->inviter?->email, $i->batch_id, $i->campaign,
                    $perks['plan'], $perks['plan_months'], $perks['credits'],
                    $i->sent_at?->toIso8601String(), $i->resend_count, $i->expires_at->toIso8601String(),
                    $i->accepted_at?->toIso8601String(), $i->accepted_user_id,
                ]));
            }

            fclose($out);
        }, 'ecrivain-invitations-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private static function sharedRules(): array
    {
        return [
            'language' => ['nullable', Rule::in(array_keys(AuthorInvitation::LANGUAGES))],
            'note' => ['nullable', 'string', 'max:1000'],
            'plan' => ['nullable', 'string', 'max:32'],
            'plan_months' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('growth.invitations.max_plan_months')],
            'credits' => ['nullable', 'integer', 'min:0'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('growth.invitations.max_days')],
            'campaign' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    /** @return array<string, mixed> */
    private static function shared(array $data): array
    {
        return [
            'language' => $data['language'] ?? 'en',
            'note' => $data['note'] ?? null,
            'perks' => [
                'plan' => $data['plan'] ?? null,
                'plan_months' => $data['plan_months'] ?? null,
                'credits' => $data['credits'] ?? null,
            ],
            'expires_days' => $data['expires_days'] ?? null,
            'campaign' => $data['campaign'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function row(AuthorInvitation $i): array
    {
        return [
            'id' => $i->id,
            'email' => $i->email,
            'name' => $i->name,
            'language' => $i->language,
            'state' => $i->state(),
            'perks' => $i->perkList(),
            'inviter' => $i->inviter ? ['id' => $i->inviter->id, 'name' => $i->inviter->name] : null,
            'batch' => $i->batch_id ? ['id' => $i->batch_id, 'label' => 'Batch #'.$i->batch_id.($i->batch?->campaign ? ' · '.$i->batch->campaign : '')] : null,
            'campaign' => $i->campaign,
            'sent_at' => $i->sent_at?->toIso8601String(),
            'created_at' => $i->created_at->toIso8601String(),
            'resend_count' => $i->resend_count,
            'last_resent_at' => $i->last_resent_at?->toIso8601String(),
            'expires_at' => $i->expires_at->toIso8601String(),
            'accepted_at' => $i->accepted_at?->toIso8601String(),
            'accepted_user' => $i->acceptedUser ? ['id' => $i->acceptedUser->id, 'name' => $i->acceptedUser->name] : null,
        ];
    }
}
