<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Models\ErrorGroup;
use App\Models\MailLog;
use App\Platform\AdminAudit;
use App\Platform\Trust\LogViewer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The application log, owner-only, redacted (App\Platform\Trust\LogViewer).
 *
 * When the log goes only to the container's stream, the page says so and
 * points at what the console does keep: error groups and the email log.
 * Opening a file is recorded in the audit log: a log can say a lot.
 */
class LogsController extends Controller
{
    public function index(Request $request, LogViewer $logs): Response
    {
        $data = $request->validate([
            'file' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', 'in:'.implode(',', LogViewer::LEVELS)],
            'q' => ['nullable', 'string', 'max:200'],
            'lines' => ['nullable', 'integer', 'in:50,200,500,1000'],
        ]);

        $described = $logs->describe();
        $file = $data['file'] ?? ($described['files'][0]['name'] ?? null);
        $entries = $file ? $logs->tail($file, (int) ($data['lines'] ?? 200), $data['level'] ?? null, $data['q'] ?? null) : [];

        if ($file && ! $request->header('X-Inertia-Partial-Data')) {
            AdminAudit::record('logs.viewed', null, null, ['file' => $file, 'level' => $data['level'] ?? null, 'search' => filled($data['q'] ?? null)], null, $request->user());
        }

        return Inertia::render('Admin/Trust/Logs', [
            'log' => $described,
            'file' => $file,
            'filters' => [
                'level' => $data['level'] ?? '',
                'q' => $data['q'] ?? '',
                'lines' => (int) ($data['lines'] ?? 200),
            ],
            'levels' => LogViewer::LEVELS,
            'entries' => $entries,
            // What there is when the log is a stream.
            'elsewhere' => [
                'open_errors' => ErrorGroup::where('status', 'open')->count(),
                'mail_failed_24h' => MailLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            ],
        ]);
    }
}
