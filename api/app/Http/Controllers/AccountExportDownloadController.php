<?php

namespace App\Http\Controllers;

use App\Models\AccountExport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The link emailed with an account's data export. Signed and expiring
 * (`signed`), so it works without signing in — the account may be
 * suspended while it is offboarded — and only until the file is removed.
 */
class AccountExportDownloadController extends Controller
{
    public function __invoke(AccountExport $export): Response
    {
        abort_unless($export->isDownloadable() && $export->user && ! $export->user->offboarded_at, 404);
        abort_unless(Storage::disk('files')->exists($export->path), 410, 'This export is no longer available.');

        return Storage::disk('files')->download($export->path, "ecrivain-account-export-{$export->id}.zip", [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
