<?php

namespace App\Platform\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An AI feature refused by a kill switch or a spend cap.
 *
 * A 503 with the sentence for the author and `code: ai_paused`, so the
 * studio shows it as a notice rather than a failure. Nothing was charged:
 * it is thrown before the credits are touched.
 */
class AiPaused extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(503, $message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json(['message' => $this->getMessage(), 'code' => 'ai_paused'], 503);
    }
}
