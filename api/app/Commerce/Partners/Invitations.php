<?php

namespace App\Commerce\Partners;

use App\Mail\Notify;
use App\Mail\Partners\PartnerInvitationMail;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An author inviting somebody to partner for their books.
 *
 * Simpler than the console's invitations (App\Growth\Invitations), which
 * create accounts with perks: this one only lets the invitee skip the
 * application queue. Accepting needs an account under the invited address;
 * the partner is approved at once but `restricted` to the inviting
 * author's books (partner_sellers) until staff open the whole catalogue. An
 * existing partner who accepts simply gains the author.
 *
 * The token is in the email only; the table keeps its SHA-256.
 */
final class Invitations
{
    /** Invitations one author may send in a day. */
    private const DAILY_LIMIT = 20;

    /** @throws ValidationException */
    public function invite(User $seller, string $email): PartnerInvitation
    {
        $email = mb_strtolower(trim($email));

        if ($email === mb_strtolower($seller->email)) {
            throw ValidationException::withMessages(['email' => 'You cannot invite yourself.']);
        }

        [$name, $titles] = self::presentation($seller);

        if ($titles === []) {
            throw ValidationException::withMessages(['email' => 'Let partners sell at least one of your books on sale first (on its storefront settings).']);
        }

        if (PartnerInvitation::where('seller_id', $seller->id)->where('created_at', '>=', now()->subDay())->count() >= self::DAILY_LIMIT) {
            throw ValidationException::withMessages(['email' => 'That is enough invitations for today. Try again tomorrow.']);
        }

        $token = Str::random(48);
        $invitation = new PartnerInvitation;
        $invitation->forceFill([
            'seller_id' => $seller->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('partners.invitation_days')),
        ])->save();

        Notify::send($email, new PartnerInvitationMail(
            $name,
            route('partners.invitation', $token),
            $titles,
            $invitation->expires_at,
        ));

        return $invitation;
    }

    public function find(string $token): ?PartnerInvitation
    {
        return PartnerInvitation::where('token_hash', hash('sha256', $token))->with('seller')->first();
    }

    /**
     * $user accepts. Their partner record is created approved (restricted)
     * if they had none; the author is added to the sellers they may sell.
     *
     * @throws ValidationException
     */
    public function accept(PartnerInvitation $invitation, User $user, ?string $agreementVersion): Partner
    {
        if (! $invitation->isOpen()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has expired or was already used.']);
        }

        if (mb_strtolower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages(['invitation' => 'This invitation was sent to another email address. Sign in with that address to accept it.']);
        }

        if ($agreementVersion !== config('partners.agreement_version')) {
            throw ValidationException::withMessages(['agreement' => 'Please read and accept the current Partner Agreement.']);
        }

        [$partner, $created] = DB::transaction(function () use ($invitation, $user) {
            $partner = Partner::where('user_id', $user->id)->lockForUpdate()->first();
            $created = false;

            if ($partner?->isSuspended()) {
                throw ValidationException::withMessages(['invitation' => 'Your partner account is suspended.']);
            }

            if (! $partner || in_array($partner->status, ['applied', 'declined'], true)) {
                $name = trim((string) ($user->author_name ?: $user->name)) ?: 'Partner';
                $partner ??= (new Partner)->forceFill([
                    'user_id' => $user->id,
                    'code' => Partner::newCode(),
                    'handle' => Handles::suggest($name),
                    'display_name' => mb_substr($name, 0, 80),
                    'promotion_type' => 'other',
                ]);

                $partner->forceFill([
                    'status' => 'approved',
                    // A partner already approved for everything stays so.
                    'restricted' => true,
                    'approved_at' => now(),
                    'agreement_version' => config('partners.agreement_version'),
                    'agreement_accepted_at' => now(),
                    'declined_at' => null,
                    'decline_reason' => null,
                ])->save();
                $created = true;
            }

            $partner->sellers()->syncWithoutDetaching([$invitation->seller_id]);
            $invitation->forceFill(['accepted_at' => now(), 'partner_id' => $partner->id])->save();

            return [$partner, $created];
        });

        if ($created) {
            PartnerNotices::approved($partner->fresh('user'));
        }

        return $partner;
    }

    /**
     * The name and the books an invitation shows.
     *
     * Only names the books themselves carry, never the account's: an
     * author who also sells anonymously must not have those books tied to
     * their name. With a named book, only named books are listed; with
     * anonymous books only, the sender is "an author on Écrivain".
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function presentation(User $seller): array
    {
        $products = Product::published()->where('user_id', $seller->id)->where('partner_enabled', true)->with('book')->get();
        $named = $products->filter(fn (Product $p) => $p->authorName() !== null);

        if ($named->isNotEmpty()) {
            return [$named->first()->authorName(), $named->map(fn (Product $p) => $p->displayTitle())->values()->all()];
        }

        return [__('An author on :brand', ['brand' => config('mail.ecrivain.brand')]), $products->map(fn (Product $p) => $p->displayTitle())->values()->all()];
    }
}
