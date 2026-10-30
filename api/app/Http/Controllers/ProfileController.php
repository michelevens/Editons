<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Http\Requests\ProfileUpdateRequest;
use App\Journey\BookType;
use App\Mail\EmailPreferences;
use App\Mail\Notify;
use App\Platform\TwoFactor;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
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
                fn (BookType $type) => ['key' => $type->key, 'name' => $type->displayName()],
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
                'books' => $user->activeBookCount(),
                'book_limit' => $limits['books'],
            ],
            'email_preferences' => EmailPreferences::describe($user),
            'two_factor' => app(TwoFactor::class)->state($user),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        // Captured before the save: the security notice goes to the address
        // the account had, which is the one the real owner still reads.
        $previousEmail = $request->user()->isDirty('email')
            ? $request->user()->getOriginal('email')
            : null;

        if ($previousEmail) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        if ($previousEmail) {
            Notify::emailChanged($request->user(), $previousEmail);
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Switch email categories on or off.
     *
     * Mandatory categories (security, billing, receipts) are ignored rather
     * than refused: EmailPreferences::update only ever stores toggleable
     * keys, so a crafted request cannot silence a security notice.
     */
    public function updateEmailPreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);

        EmailPreferences::update($request->user(), $data['preferences']);

        return Redirect::route('profile.edit')->with('status', 'email-preferences-updated');
    }

    /**
     * Light, dark, or whatever the device prefers.
     *
     * JSON rather than a redirect: the switch is in the account menu on every
     * screen, and the page has already re-themed itself by the time this
     * answers, so there is nothing to reload.
     */
    public function updateTheme(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(User::THEMES)],
        ]);

        $request->user()->forceFill(['theme' => $data['theme']])->save();

        return response()->json(['theme' => $data['theme']]);
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

        // Plain values, read before the row goes: the confirmation is
        // rendered by a queue worker after the account no longer exists.
        $farewell = [$user->name, $user->email, $user->books()->withTrashed()->count()];

        // Stop the billing before the account goes. The subscription rows
        // cascade with the user, and a Stripe subscription nobody can see
        // any more would go on charging the card. If Stripe cannot be
        // reached the account stays, rather than being deleted mid-billing.
        foreach ($user->subscriptions()->active()->get() as $subscription) {
            try {
                $subscription->cancelNow();
            } catch (\Throwable $e) {
                report($e);

                return back()->withErrors([
                    'password' => 'Your subscription could not be cancelled just now, so the account was not deleted. Try again in a moment.',
                ]);
            }
        }

        Auth::logout();

        $user->delete();

        Notify::accountDeleted(...$farewell);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
