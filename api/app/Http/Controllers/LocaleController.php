<?php

namespace App\Http\Controllers;

use App\I18n\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The language switcher (account menu, Account settings, sign-in pages).
 *
 * Signed in, the choice is the account's; signed out, the session's, which
 * the account created from that session keeps (SetLocale stores it on the
 * first signed-in request).
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::SUPPORTED)],
        ]);

        $request->session()->put('locale', $data['locale']);
        $request->user()?->forceFill(['locale' => $data['locale']])->save();

        return back(303);
    }
}
