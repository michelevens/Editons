<?php

namespace App\Http\Controllers;

use App\Platform\Ops\ErrorGroups;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Errors the browser caught (window.onerror, unhandled rejections), sent
 * by resources/js/errorReporter.js.
 *
 * Rate-limited per person and IP, size-capped before it is even parsed,
 * and stored with nothing about the person but their account id.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): Response
    {
        if ((int) $request->header('Content-Length', '0') > (int) config('platform.errors.client_max_bytes', 16384)
            || strlen($request->getContent()) > (int) config('platform.errors.client_max_bytes', 16384)) {
            abort(413, 'Error report too large.');
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'url' => ['nullable', 'string', 'max:500'],
        ]);

        ErrorGroups::captureClient($data['message'], $data['stack'] ?? null, $data['url'] ?? null, $request->user()?->id);

        return response()->noContent();
    }
}
