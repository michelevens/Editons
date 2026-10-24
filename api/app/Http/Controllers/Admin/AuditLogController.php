<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Platform\AdminAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The admin audit log: filter it, read it, take a CSV of it. */
class AuditLogController extends Controller
{
    /** The most rows one export carries; narrow the filters for more. */
    private const EXPORT_LIMIT = 10000;

    private const FILTERS = ['action', 'actor', 'subject_type', 'subject_id', 'from', 'to'];

    public function index(Request $request): Response
    {
        $logs = $this->filtered($request)
            ->with(['actor:id,name,email', 'impersonator:id,name'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Audit', [
            'logs' => $logs->through(fn (AdminAuditLog $log) => $this->row($log)),
            'filters' => $request->only(self::FILTERS),
            'actions' => AdminAuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)
            ->with(['actor:id,email', 'impersonator:id,email'])
            ->latest('id')
            ->limit(self::EXPORT_LIMIT);

        // Taking a copy of the log is itself on the log.
        AdminAudit::transaction(fn () => AdminAudit::record(
            'audit.exported',
            after: ['filters' => $request->only(self::FILTERS)],
        ));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'at', 'action', 'actor', 'impersonator', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'ip', 'user_agent']);

            foreach ($query->get() as $log) {
                fputcsv($out, array_map([self::class, 'cell'], [
                    $log->id,
                    $log->created_at?->toIso8601String(),
                    $log->action,
                    $log->actor?->email ?? 'system',
                    $log->impersonator?->email,
                    $log->subject_type,
                    $log->subject_id,
                    $log->before ? json_encode($log->before) : '',
                    $log->after ? json_encode($log->after) : '',
                    $log->reason,
                    $log->ip,
                    $log->user_agent,
                ]));
            }

            fclose($out);
        }, 'ecrivain-admin-audit-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A spreadsheet runs a cell that starts with = + - @ as a formula, and a
     * reason is typed by a person: prefix those with a quote.
     */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function filtered(Request $request): Builder
    {
        return AdminAuditLog::query()
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('actor'), function ($q, $actor) {
                ctype_digit((string) $actor)
                    ? $q->where('actor_id', (int) $actor)
                    : $q->whereHas('actor', fn ($a) => $a->where('email', 'ilike', '%'.$actor.'%'));
            })
            ->when($request->query('subject_type'), fn ($q, $type) => $q->where('subject_type', $type))
            ->when($request->query('subject_id'), fn ($q, $id) => $q->where('subject_id', (int) $id))
            ->when($request->query('from'), fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->query('to'), fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()));
    }

    /** @return array<string, mixed> */
    private function row(AdminAuditLog $log): array
    {
        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'action' => $log->action,
            'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'email' => $log->actor->email] : null,
            'impersonator' => $log->impersonator?->name,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'before' => $log->before,
            'after' => $log->after,
            'reason' => $log->reason,
            'ip' => $log->ip,
        ];
    }
}
