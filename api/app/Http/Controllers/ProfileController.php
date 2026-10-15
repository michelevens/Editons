<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Http\Requests\ProfileUpdateRequest;
use App\Journey\BookType;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $limits = $user->planLimits();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'author' => [
                'author_name' => $user->author_name,
                'bio' => $user->bio,
                'website' => $user->website,
                'default_book_type' => $user->default_book_type,
                'daily_word_goal' => $user->daily_word_goal,
            ],
            'book_types' => array_map(
                fn (BookType $type) => ['key' => $type->key, 'name' => $type->name()],
                BookType::all(),
            ),
            // Shown here as well as on Plans & Billing: an author checking
            // their own details should not have to go somewhere else to see
            // what they are paying for.
            'plan' => [
                'key' => $user->plan,
                'name' => $limits['name'],
                'price' => $limits['price'],
                'credits' => app(CreditLedger::class)->balanceFor($user),
                'monthly_credits' => $limits['credits'],
                'books' => $user->books()->count(),
                'book_limit' => $limits['books'],
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
