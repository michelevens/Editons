<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletion;
use App\Models\AccountExport;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\Trust\AccountExporter;
use App\Platform\Trust\Offboarding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Offboarding: data exports and account deletion (App\Platform\Trust\Offboarding).
 *
 * Staff can read the list and an account's dry run; everything that builds
 * a copy of somebody's books or deletes them is the owner's.
 */
class OffboardingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Trust/Offboarding', [
            'deletions' => AccountDeletion::with('requester:id,name', 'user:id,name,email,offboarded_at')->latest('id')->limit(100)->get()
                ->map(fn (AccountDeletion $d) => self::deletionRow($d)),
            'exports' => AccountExport::with('user:id,name,email', 'requester:id,name')->latest('id')->limit(50)->get()
                ->map(fn (AccountExport $e) => self::exportRow($e)),
        ]);
    }

    public function show(Request $request, User $user, Offboarding $offboarding): Response
    {
        return Inertia::render('Admin/Trust/OffboardAccount', [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'offboarded_at' => $user->offboarded_at?->toIso8601String(),
                'suspended' => $user->isSuspended(),
            ],
            'plan' => $user->offboarded_at ? null : $offboarding->plan($user),
            'deletions' => AccountDeletion::where('user_id', $user->id)->with('requester:id,name')->latest('id')->get()
                ->map(fn (AccountDeletion $d) => self::deletionRow($d)),
            'exports' => AccountExport::where('user_id', $user->id)->with('requester:id,name')->latest('id')->limit(10)->get()
                ->map(fn (AccountExport $e) => self::exportRow($e)),
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    public function export(Request $request, User $user, AccountExporter $exporter): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:500'],
            'email_account' => ['boolean'],
        ]);

        $exporter->request($user, $request->user(), (bool) ($data['email_account'] ?? false), $data['reason']);

        return back()->with('platform_notice', 'Export queued: it is built on the worker.');
    }

    public function download(Request $request, AccountExport $export): HttpResponse
    {
        abort_unless($export->isDownloadable() && Storage::disk('files')->exists($export->path), 404);

        AdminAudit::record('account.export_downloaded', $export->user ?? $export, null, ['export_id' => $export->id], null, $request->user());

        return Storage::disk('files')->download($export->path, "ecrivain-account-{$export->user_id}-export-{$export->id}.zip", [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function schedule(Request $request, User $user, Offboarding $offboarding): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
            'confirm_email' => ['required', 'string', 'max:255'],
        ]);

        $deletion = $offboarding->schedule($user, $data['reason'], $data['confirm_email'], $request->user());

        return back()->with('platform_notice', 'Deletion scheduled for '.$deletion->scheduled_for->toDayDateTimeString().'. The account is suspended until then; it can be cancelled.');
    }

    public function cancel(Request $request, AccountDeletion $deletion, Offboarding $offboarding): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:1000']]);

        $offboarding->cancel($deletion, $data['reason'], $request->user());

        return back()->with('platform_notice', 'Deletion cancelled.');
    }

    public function execute(Request $request, AccountDeletion $deletion, Offboarding $offboarding): RedirectResponse
    {
        $data = $request->validate(['confirm_email' => ['required', 'string', 'max:255']]);

        $done = $offboarding->execute($deletion, $data['confirm_email'], $request->user());

        return back()->with('platform_notice', 'Account deleted. Certificate '.substr((string) $done->certificate_sha256, 0, 12).'… is ready.');
    }

    public function certificate(Request $request, AccountDeletion $deletion): HttpResponse
    {
        abort_unless($deletion->status === 'completed' && $deletion->certificate, 404);

        $format = $request->query('format') === 'json' ? 'json' : 'html';

        if ($format === 'json') {
            return response()->json($deletion->certificate + ['sha256' => $deletion->certificate_sha256], 200, [
                'Content-Disposition' => "attachment; filename=\"deletion-certificate-{$deletion->id}.json\"",
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return response()->view('platform.deletion-certificate', ['c' => $deletion->certificate, 'sha256' => $deletion->certificate_sha256]);
    }

    /** @return array<string, mixed> */
    private static function deletionRow(AccountDeletion $d): array
    {
        return [
            'id' => $d->id,
            'user_id' => $d->user_id,
            'account' => $d->relationLoaded('user') && $d->user ? ['name' => $d->user->name, 'email' => $d->user->email] : null,
            'status' => $d->status,
            'reason' => $d->reason,
            'requested_by' => $d->requester?->name,
            'created_at' => $d->created_at?->toIso8601String(),
            'scheduled_for' => $d->scheduled_for?->toIso8601String(),
            'grace_over' => $d->status === 'scheduled' && $d->graceOver(),
            'cancelled_at' => $d->cancelled_at?->toIso8601String(),
            'cancel_reason' => $d->cancel_reason,
            'completed_at' => $d->completed_at?->toIso8601String(),
            'certificate_sha256' => $d->certificate_sha256,
            'books' => $d->plan['delete']['books'] ?? null,
            'files' => $d->plan['files']['count'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function exportRow(AccountExport $e): array
    {
        return [
            'id' => $e->id,
            'user_id' => $e->user_id,
            'account' => $e->relationLoaded('user') && $e->user ? ['name' => $e->user->name, 'email' => $e->user->email] : null,
            'status' => $e->status,
            'requested_by' => $e->requester?->name,
            'bytes' => $e->byte_size,
            'contents' => $e->contents,
            'sha256' => $e->sha256,
            'error' => $e->error,
            'email_account' => $e->email_account,
            'emailed_at' => $e->emailed_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
            'downloadable' => $e->isDownloadable(),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
