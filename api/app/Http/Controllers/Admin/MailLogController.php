<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use App\Platform\Ops\MailLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The email log: what was handed to the mailer, what failed, and a resend
 * for the ones that can safely be built again.
 *
 * Bounces and complaints are not shown because they are not known: they
 * need the provider's webhook, which is not connected. The page says so.
 */
class MailLogController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $logs = MailLog::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(fn ($w) => $w->where('to', 'ilike', $like)
                    ->orWhere('subject', 'ilike', $like)
                    ->orWhere('mailable', 'ilike', $like)
                    ->orWhere('provider_message_id', 'ilike', $like));
            })
            ->when(in_array($status, ['sent', 'failed'], true), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Mail', [
            'q' => $q,
            'status' => $status,
            'logs' => $logs->through(fn (MailLog $log) => [
                'id' => $log->id,
                'to' => $log->to,
                'user_id' => $log->user_id,
                'mailable' => $log->mailable ? class_basename($log->mailable) : null,
                'subject' => $log->subject,
                'category' => $log->category,
                'status' => $log->status,
                'provider_message_id' => $log->provider_message_id,
                'sensitive' => $log->sensitive,
                // The body only for mail that carried no secret.
                'body_text' => $log->sensitive ? null : $log->body_text,
                'error' => $log->error,
                'resendable' => $log->resendable(),
                'resent_from_id' => $log->resent_from_id,
                'at' => $log->created_at?->toIso8601String(),
            ]),
            'mailer' => config('mail.default'),
            // No webhook from the provider is received yet.
            'delivery_tracking' => false,
        ]);
    }

    public function resend(Request $request, MailLog $log): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        MailLogger::resend($log, $request->user(), $data['reason']);

        return back()->with('platform_notice', 'Sent again to '.$log->to.'.');
    }
}
