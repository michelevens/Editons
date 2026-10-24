<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\TwoFactor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one console page staff without two-factor can reach.
 *
 * The forms post to the same endpoints as the profile's
 * (TwoFactorController); this page only frames them inside the console.
 */
class TwoFactorSetupController extends Controller
{
    public function show(Request $request, TwoFactor $twoFactor): Response
    {
        return Inertia::render('Admin/TwoFactor', [
            'two_factor' => $twoFactor->state($request->user()),
        ]);
    }
}
