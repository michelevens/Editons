<?php

namespace App\Commerce\Partners;

use App\Models\Partner;
use App\Models\PartnerHandle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A partner's address: /p/{handle}.
 *
 * 3–30 characters of lowercase letters, digits and single hyphens, not at
 * either end. Words that would let a page pass for Écrivain's own, or
 * shadow a route, are refused (config partners.reserved_handles). A handle
 * is unique across current AND past handles: a handle somebody left behind
 * redirects to their new one, so nobody else can take it and inherit their
 * printed QR codes.
 *
 * A partner may change it at most once every 30 days
 * (config partners.handle_change_days).
 */
final class Handles
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){2,29}$/';

    /** Why a handle cannot be used, or null. */
    public static function problem(string $handle, ?Partner $for = null): ?string
    {
        if (! preg_match(self::PATTERN, $handle)) {
            return 'Use 3 to 30 lowercase letters, digits or hyphens, starting and ending with a letter or digit.';
        }

        if (in_array($handle, (array) config('partners.reserved_handles'), true)) {
            return 'That address is reserved. Choose another.';
        }

        $taken = Partner::where('handle', $handle)->when($for, fn ($q) => $q->whereKeyNot($for->id))->exists()
            || PartnerHandle::where('handle', $handle)->when($for, fn ($q) => $q->where('partner_id', '!=', $for->id))->exists();

        return $taken ? 'That address is taken. Choose another.' : null;
    }

    /** A free handle made from a name: "Lekòl Sen Jozèf" → "lekol-sen-jozef". */
    public static function suggest(string $name): string
    {
        $base = Str::limit(trim(Str::slug(Str::ascii($name)), '-'), 24, '');
        $base = strlen($base) >= 3 ? $base : 'partner';

        if (self::problem($base) === null) {
            return $base;
        }

        do {
            $candidate = rtrim($base, '-').'-'.Str::lower(Str::random(4));
        } while (self::problem($candidate) !== null);

        return $candidate;
    }

    /**
     * Move a partner to a new handle. The old one is kept, and redirects.
     *
     * @throws ValidationException under `handle`
     */
    public static function change(Partner $partner, string $handle): void
    {
        $handle = strtolower(trim($handle));

        if ($handle === $partner->handle) {
            return;
        }

        $days = (int) config('partners.handle_change_days');

        if ($partner->handle_changed_at && $partner->handle_changed_at->gt(now()->subDays($days))) {
            throw ValidationException::withMessages([
                'handle' => 'You can change your address once every '.$days.' days. Next change from '.$partner->handle_changed_at->copy()->addDays($days)->toFormattedDateString().'.',
            ]);
        }

        if ($problem = self::problem($handle, $partner)) {
            throw ValidationException::withMessages(['handle' => $problem]);
        }

        DB::transaction(function () use ($partner, $handle) {
            // Moving back to a handle of one's own past: it is no longer old.
            PartnerHandle::where('partner_id', $partner->id)->where('handle', $handle)->delete();

            $old = new PartnerHandle;
            $old->forceFill(['partner_id' => $partner->id, 'handle' => $partner->handle, 'retired_at' => now()])->save();

            $partner->forceFill(['handle' => $handle, 'handle_changed_at' => now()])->save();
        });
    }

    /** The partner a handle names now, or used to, with whether it moved. @return array{0: ?Partner, 1: bool} */
    public static function resolve(string $handle): array
    {
        $handle = strtolower($handle);

        if ($partner = Partner::where('handle', $handle)->first()) {
            return [$partner, false];
        }

        $old = PartnerHandle::where('handle', $handle)->with('partner')->first();

        return [$old?->partner, $old !== null];
    }
}
