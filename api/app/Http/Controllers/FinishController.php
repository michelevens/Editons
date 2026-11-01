<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Publishing\FinishChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Finish my book" checklist (ADR — AI research, source-backed
 * fact-checking, cover templates). Read-only: each step is run by the
 * author through its own existing endpoint, with its own approval.
 */
class FinishController extends Controller
{
    public function show(Request $request, Book $book, FinishChecklist $checklist): JsonResponse
    {
        $this->authorize('update', $book);

        return response()->json($checklist->for($book, $request->user()));
    }
}
