<?php

namespace App\Http\Controllers;

use App\Workspace\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An author's keyboard shortcuts, phone tabs and review-deck swipes
 * (App\Workspace\Preferences). JSON, like the workspace layout beside it.
 */
class PreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(Preferences::for($request->user()));
    }

    /** Change some of them; a null value is "back to the default". */
    public function update(Request $request): JsonResponse
    {
        // Validated key by key against the registry; read raw so validate()
        // has not reshaped it.
        return response()->json(Preferences::save($request->user(), $request->all()));
    }

    /** One section back to its defaults: "Reset all" in the shortcut sheet. */
    public function destroy(Request $request, string $section): JsonResponse
    {
        validator(['section' => $section], [
            'section' => ['required', Rule::in(['shortcuts', 'mobileTabs', 'reviewDeck'])],
        ])->validate();

        return response()->json(Preferences::reset($request->user(), $section));
    }
}
