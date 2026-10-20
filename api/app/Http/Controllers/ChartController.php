<?php

namespace App\Http\Controllers;

use App\Publishing\ChartRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Draws a chart for the editor, from the same renderer the exports use, so
 * what the author sees is what prints.
 */
class ChartController extends Controller
{
    public function preview(Request $request, ChartRenderer $charts): JsonResponse
    {
        $spec = $request->validate([
            'type' => ['required', 'in:'.implode(',', ChartRenderer::TYPES)],
            'title' => ['nullable', 'string', 'max:200'],
            'xLabel' => ['nullable', 'string', 'max:120'],
            'yLabel' => ['nullable', 'string', 'max:120'],
            // A thesis table, not a dataset: a few hundred rows at most.
            'data' => ['required', 'string', 'max:20000'],
        ]);

        try {
            return response()->json([
                'svg' => $charts->svg($spec),
                'description' => $charts->describe($spec),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['data' => [$e->getMessage()]]], 422);
        }
    }
}
