<?php

namespace App\Http\Controllers;

use App\Growth\Attribution;
use App\Growth\Invitations\Invitations;
use App\Mail\Growth\AuthorInvitationMail;
use App\Models\AuthorInvitation;
use App\Models\User;
use App\Platform\LoginHistory;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /join/{token}: where an invitation from the console leads
 * (App\Growth\Invitations\Invitations).
 *
 * Reachable signed out, and open while new sign-ups are closed — an
 * invite-only beta is the reason invitations exist. A link that has
 * expired, been revoked or used is a friendly page with a way to ask for a
 * new one, never an error.
 */
class JoinController extends Controller
{
    public function __construct(private readonly Invitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = AuthorInvitation::findByToken($token);
        $state = $invitation?->state() ?? 'unknown';
        $user = $request->user();
        $live = $state === 'pending';

        $accountExists = $live && ! $user
            && User::whereRaw('lower(email) = ?', [$invitation->email])->exists();

        // They already have an account (made since the invitation): send them
        // back here after signing in, to accept with it.
        if ($accountExists) {
            $request->session()->put('url.intended', route('join.show', $token));
        }

        return Inertia::render('Join', [
            'state' => $state,
            'token' => $live ? $token : null,
            'decline' => $live && $request->boolean('decline'),
            'invitation' => $live ? [
                'email' => $invitation->email,
                'name' => $invitation->name,
                'language' => $invitation->language,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'perks' => (new AuthorInvitationMail($invitation, $token))->perkLines(),
            ] : null,
            // Only on a live link: whether the signed-in account is the one invited.
            'signed_in_as' => $live && $user ? $user->email : null,
            'email_matches' => $live && $user ? Invitations::normalizeEmail($user->email) === $invitation->email : null,
            'account_exists' => $accountExists,
            'contact' => config('mail.ecrivain.support_address'),
        ]);
    }

    /** Create the account from the invitation: the address is the invitation's. */
    public function register(Request $request, string $token): RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('join.show', $token);
        }

        $invitation = $this->live($token);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if (User::whereRaw('lower(email) = ?', [$invitation->email])->exists()) {
            throw ValidationException::withMessages(['email' => __('This address already has an account. Sign in to accept the invitation.')]);
        }

        // One transaction: the account, the acceptance and its perks stand
        // or fall together.
        $user = DB::transaction(function () use ($data, $invitation, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
            ]);

            // The cookie's referrer and landing page, then the invitation's
            // source on top: it is why this account exists.
            Attribution::apply($user, $request);
            $this->invitations->accept($invitation, $user, newAccount: true);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();
        LoginHistory::record($user, 'registration', $request);

        return redirect(route('dashboard', absolute: false))
            ->with('platform_notice', __('Welcome to :brand. Your invitation is accepted.', ['brand' => config('mail.ecrivain.brand')]));
    }

    /** Signed in already, with the invited address: accept with this account. */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->live($token);

        if (Invitations::normalizeEmail($request->user()->email) !== $invitation->email) {
            throw ValidationException::withMessages(['email' => __('This invitation was sent to a different email address.')]);
        }

        $this->invitations->accept($invitation, $request->user(), newAccount: false);

        return redirect(route('dashboard', absolute: false))
            ->with('platform_notice', __('Invitation accepted.'));
    }

    /** "Don't invite me again." */
    public function decline(Request $request, string $token): RedirectResponse
    {
        $invitation = AuthorInvitation::findByToken($token);

        if ($invitation) {
            $this->invitations->decline($invitation);
        }

        return redirect()->route('join.show', $token);
    }

    private function live(string $token): AuthorInvitation
    {
        $invitation = AuthorInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isLive()) {
            throw ValidationException::withMessages(['email' => __('This invitation is no longer valid.')]);
        }

        return $invitation;
    }
}
