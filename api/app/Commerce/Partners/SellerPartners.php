<?php

namespace App\Commerce\Partners;

use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The seller's view of the partners selling their books: who (the
 * partner's public name and page — never their account), how many sales,
 * and the switch to block one from all of their books. Blocking takes
 * effect at the next checkout; a commission already earned stays earned.
 */
final class SellerPartners
{
    /** @return array{partners: list<array<string, mixed>>, invitations: list<array<string, mixed>>, opted_in: int} */
    public static function for(User $seller): array
    {
        $sales = PartnerCommission::where('seller_id', $seller->id)->where('kind', 'sale')
            ->select('partner_id', 'currency', DB::raw('count(*) as units'), DB::raw('sum(amount_cents - reversed_cents) as commission'))
            ->groupBy('partner_id', 'currency')
            ->get()
            ->groupBy('partner_id');

        $blocked = DB::table('partner_blocks')->where('seller_id', $seller->id)->pluck('partner_id')->all();
        $shelved = DB::table('partner_shelf_items as s')->join('products as p', 'p.id', '=', 's.product_id')
            ->where('p.user_id', $seller->id)->distinct()->pluck('s.partner_id')->all();

        $ids = collect($sales->keys())->merge($blocked)->merge($shelved)->unique()->values();

        $partners = Partner::whereIn('id', $ids)->get()->map(fn (Partner $p) => [
            'id' => $p->id,
            'name' => $p->display_name,
            'url' => $p->isApproved() ? $p->pageUrl() : null,
            'status' => $p->status === 'approved' ? 'active' : 'inactive',
            'on_page' => in_array($p->id, $shelved, true),
            'sales' => (int) ($sales[$p->id] ?? collect())->sum('units'),
            'commissions' => ($sales[$p->id] ?? collect())->map(fn ($row) => ['currency' => $row->currency, 'cents' => (int) $row->commission])->values()->all(),
            'blocked' => in_array($p->id, $blocked, true),
        ])->sortByDesc('sales')->values()->all();

        return [
            'partners' => $partners,
            'invitations' => PartnerInvitation::where('seller_id', $seller->id)->latest('id')->limit(20)->get()->map(fn (PartnerInvitation $i) => [
                'email' => $i->email,
                'status' => $i->accepted_at ? 'accepted' : ($i->expires_at->isPast() ? 'expired' : 'sent'),
                'sent_at' => $i->created_at->toIso8601String(),
            ])->all(),
            'opted_in' => $seller->products()->where('partner_enabled', true)->count(),
        ];
    }

    public static function block(User $seller, Partner $partner): void
    {
        DB::table('partner_blocks')->insertOrIgnore([
            'seller_id' => $seller->id,
            'partner_id' => $partner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Off the partner's page at once: their shelf only shows what they
        // may sell, and this seller's books are no longer that.
    }

    public static function unblock(User $seller, Partner $partner): void
    {
        DB::table('partner_blocks')->where('seller_id', $seller->id)->where('partner_id', $partner->id)->delete();
    }
}
