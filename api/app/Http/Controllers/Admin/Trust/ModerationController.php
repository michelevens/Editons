<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Platform\Trust\Moderation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The moderation queue: reports filed from public storefront pages. */
class ModerationController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'open');

        $reports = ContentReport::query()
            ->when(in_array($status, ContentReport::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->with(['product' => fn ($p) => $p->with(['book' => fn ($b) => $b->withTrashed()->select('id', 'title', 'deleted_at'), 'user:id,name,email']), 'resolver:id,name', 'partner'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Admin/Trust/Moderation', [
            'status' => $status,
            'categories' => ContentReport::CATEGORIES,
            'counts' => ContentReport::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'reports' => $reports->through(fn (ContentReport $r) => [
                'id' => $r->id,
                'category' => $r->category,
                'category_label' => ContentReport::CATEGORIES[$r->category] ?? $r->category,
                'details' => $r->details,
                'reporter_email' => $r->reporter_email,
                'status' => $r->status,
                'resolution' => $r->resolution,
                'resolver' => $r->resolver?->name,
                'resolved_at' => $r->resolved_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
                // How many reports came from the same place: one person
                // filing twenty is worth knowing.
                'same_source' => $r->reporter_ip_hash ? ContentReport::where('reporter_ip_hash', $r->reporter_ip_hash)->count() : null,
                'author_handle' => $r->author_handle,
                'author_url' => $r->author_handle ? route('store.author', $r->author_handle) : null,
                // A partner's page (Écrivain Partners): staff suspend from
                // the partner's console page.
                'partner' => $r->partner ? [
                    'id' => $r->partner->id,
                    'name' => $r->partner->display_name,
                    'url' => $r->partner->pageUrl(),
                    'admin_url' => route('admin.partners.show', $r->partner->id),
                ] : null,
                'product' => $r->product ? [
                    'id' => $r->product->id,
                    'title' => $r->product->displayTitle(),
                    'url' => route('store.product', $r->product->slug),
                    'taken_down' => $r->product->isTakenDown(),
                    'account' => $r->product->user ? ['id' => $r->product->user->id, 'name' => $r->product->user->name, 'email' => $r->product->user->email] : null,
                ] : null,
            ]),
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    public function update(Request $request, ContentReport $report, Moderation $moderation): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:resolved,dismissed'],
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $moderation->settle($report, $data['status'], $data['reason'], $request->user());

        return back()->with('platform_notice', 'Report '.$data['status'].'.');
    }
}
