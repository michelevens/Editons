<?php

namespace App\Http\Controllers\Auth;

use App\Editorial\EditorialAgent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\LoginHistory;
use App\Platform\PlatformSettings;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        // What the free plan gives, from the plan and the agent catalogue
        // rather than written into the page: the copy said "all seven
        // editorial agents ... free" when there were nine and four ran on
        // the free plan.
        $free = config('billing.plans.free');
        $agents = EditorialAgent::cases();

        return Inertia::render('Auth/Register', [
            // Closed from the admin console's settings: the page says so
            // instead of offering a form that will refuse.
            'signups_open' => (bool) app(PlatformSettings::class)->get('new_signups_open'),
            'offer' => [
                'books' => $free['books'],
                'credits' => (int) $free['credits'],
                'agents_total' => count($agents),
                'agents_free' => array_values(array_map(
                    fn (EditorialAgent $agent) => $agent->label(),
                    array_filter($agents, fn (EditorialAgent $agent) => $agent->minimumTier() <= ($free['tier'] ?? 0)),
                )),
            ],
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if (! app(PlatformSettings::class)->get('new_signups_open')) {
            throw ValidationException::withMessages([
                'email' => 'New accounts are not open at the moment. Please try again later.',
            ]);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);
        LoginHistory::record($user, 'registration', $request);

        return redirect(route('dashboard', absolute: false));
    }
}
