<?php

namespace App\Http\Controllers;

use App\Commerce\Partners\Invitations;
use App\Commerce\Partners\SellerPartners;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A seller and the partners who sell their books: block one, let them back,
 * or invite somebody to partner. Always the signed-in seller's own books:
 * a block is keyed on the seller, so it cannot touch anybody else's.
 */
class SellerPartnersController extends Controller
{
    public function block(Request $request, Partner $partner): RedirectResponse
    {
        abort_if($partner->user_id === $request->user()->id, 404);

        SellerPartners::block($request->user(), $partner);

        return back()->with('platform_notice', $partner->display_name.' can no longer sell your books.');
    }

    public function unblock(Request $request, Partner $partner): RedirectResponse
    {
        SellerPartners::unblock($request->user(), $partner);

        return back()->with('platform_notice', $partner->display_name.' can sell your books again.');
    }

    public function invite(Request $request, Invitations $invitations): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);

        $invitations->invite($request->user(), $data['email']);

        return back()->with('platform_notice', 'Invitation sent to '.mb_strtolower($data['email']).'.');
    }
}
