<?php

namespace App\Http\Controllers;

use App\Models\BookExport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A print file for Lulu to download (App\Commerce\Print\LuluPrinting::link).
 *
 * Lulu's validation and print jobs fetch the interior and cover from a
 * public URL; this is that URL, signed and expiring, for one print
 * interior or cover wrap and nothing else. No sign-in: Lulu has none here.
 */
class PrintFileController extends Controller
{
    public function __invoke(BookExport $export): StreamedResponse
    {
        abort_unless(in_array($export->format, ['pdf_interior', 'cover_wrap'], true), 404);
        abort_unless($export->status === 'ready' && $export->path && Storage::disk('files')->exists($export->path), 404);

        return Storage::disk('files')->response($export->path, $export->format === 'cover_wrap' ? 'cover.pdf' : 'interior.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
