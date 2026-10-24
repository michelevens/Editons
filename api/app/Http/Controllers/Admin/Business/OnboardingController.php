<?php

namespace App\Http\Controllers\Admin\Business;

use App\Growth\Onboarding;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How far accounts get: each setup step, how many have done it, and who is
 * stuck at it (the first step they have not done). Read only.
 */
class OnboardingController extends Controller
{
    public function index(Request $request, Onboarding $onboarding): Response
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90, 365], true) ? (int) $request->query('days') : null;
        $step = array_key_exists((string) $request->query('step'), Onboarding::STEPS) ? $request->query('step') : null;

        return Inertia::render('Admin/Business/Onboarding', [
            'overview' => $onboarding->overview($days),
            'days' => $days,
            'step' => $step,
            'stuck' => $step ? $onboarding->stuckAt($step, $days) : [],
        ]);
    }
}
