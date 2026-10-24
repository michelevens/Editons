<?php

namespace App\Commerce\Partners;

use App\Models\DiscountCode;
use App\Models\Partner;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Becoming a partner, and the console's decisions about one.
 *
 * Anybody with an account applies (`applied`); staff approve or decline,
 * with a reason for a decline; the owner suspends and reinstates. Every
 * staff action is audited in the same transaction (AdminAudit), and the
 * partner is told by email and in their inbox once it is committed.
 *
 * A declined applicant may apply again: the same row goes back to
 * `applied`. An approved or suspended partner cannot re-apply.
 */
final class Applications
{
    /**
     * @param  array{display_name: string, country: ?string, promotion_type: string, promotion_note: ?string, links: list<string>}  $data
     *
     * @throws ValidationException
     */
    public function apply(User $user, array $data, ?string $agreementVersion): Partner
    {
        if ($agreementVersion !== config('partners.agreement_version')) {
            throw ValidationException::withMessages(['agreement' => 'Please read and accept the current Partner Agreement.']);
        }

        $partner = DB::transaction(function () use ($user, $data) {
            $partner = Partner::where('user_id', $user->id)->lockForUpdate()->first();

            if ($partner && $partner->status !== 'declined') {
                throw ValidationException::withMessages(['display_name' => $partner->status === 'applied'
                    ? 'Your application is already in the queue.'
                    : 'You are already a partner.']);
            }

            $partner ??= new Partner;
            $fresh = ! $partner->exists;

            $partner->forceFill([
                'user_id' => $user->id,
                'status' => 'applied',
                'display_name' => trim($data['display_name']),
                'country' => $data['country'] ? strtoupper($data['country']) : null,
                'promotion_type' => $data['promotion_type'],
                'promotion_note' => filled($data['promotion_note'] ?? null) ? trim(strip_tags($data['promotion_note'])) : null,
                'links' => array_values($data['links']),
                'agreement_version' => config('partners.agreement_version'),
                'agreement_accepted_at' => now(),
                'declined_at' => null,
                'decline_reason' => null,
            ]);

            if ($fresh) {
                $partner->forceFill([
                    'code' => Partner::newCode(),
                    'handle' => Handles::suggest($data['display_name']),
                ]);
            }

            $partner->save();

            return $partner;
        });

        PartnerNotices::applied($partner->load('user'));

        return $partner;
    }

    public function approve(Partner $partner, ?string $reason, User $actor): void
    {
        $this->move($partner, ['applied'], 'approved', 'partner.approved', $reason ?: 'Approved', $actor, [
            'approved_at' => now(),
            'approved_by' => $actor->id,
            'declined_at' => null,
            'decline_reason' => null,
        ]);

        PartnerNotices::approved($partner->fresh('user'));
    }

    public function decline(Partner $partner, string $reason, User $actor): void
    {
        $this->move($partner, ['applied'], 'declined', 'partner.declined', $reason, $actor, [
            'declined_at' => now(),
            'decline_reason' => trim($reason),
        ]);

        PartnerNotices::declined($partner->fresh('user'), trim($reason));
    }

    public function suspend(Partner $partner, string $reason, User $actor): void
    {
        $this->move($partner, ['approved'], 'suspended', 'partner.suspended', $reason, $actor, [
            'suspended_at' => now(),
            'suspend_reason' => trim($reason),
            'balance_released_at' => null,
        ]);

        PartnerNotices::suspended($partner->fresh('user'), trim($reason));
    }

    public function reinstate(Partner $partner, string $reason, User $actor): void
    {
        $this->move($partner, ['suspended'], 'approved', 'partner.reinstated', $reason, $actor, [
            'suspended_at' => null,
            'suspend_reason' => null,
            'balance_released_at' => null,
        ]);
    }

    /** Staff note that a tax form came in (or take it back). Nothing is collected here. */
    public function markTaxForm(Partner $partner, bool $received, string $reason, User $actor): void
    {
        AdminAudit::transaction(function () use ($partner, $received, $reason, $actor) {
            $before = $partner->tax_form_received_at !== null;

            $partner->forceFill([
                'tax_form_received_at' => $received ? now() : null,
                'tax_form_marked_by' => $received ? $actor->id : null,
            ])->save();

            AdminAudit::record('partner.tax_form', $partner, ['received' => $before], ['received' => $received], $reason, $actor);
        });
    }

    /** Staff open an invited partner's account to the whole catalogue. */
    public function unrestrict(Partner $partner, string $reason, User $actor): void
    {
        AdminAudit::transaction(function () use ($partner, $reason, $actor) {
            $partner->forceFill(['restricted' => false])->save();

            AdminAudit::record('partner.unrestricted', $partner, ['restricted' => true], ['restricted' => false], $reason, $actor);
        });
    }

    /**
     * Attach a storefront discount code to a partner (or detach it): a sale
     * with that code is the partner's, cookie or not.
     *
     * @throws ValidationException
     */
    public function attachCode(Partner $partner, ?DiscountCode $code, string $reason, User $actor): void
    {
        AdminAudit::transaction(function () use ($partner, $code, $reason, $actor) {
            $previous = DiscountCode::where('partner_id', $partner->id)->pluck('code')->all();

            if ($code === null) {
                DiscountCode::where('partner_id', $partner->id)->update(['partner_id' => null]);
                AdminAudit::record('partner.code_detached', $partner, ['codes' => $previous], ['codes' => []], $reason, $actor);

                return;
            }

            if (! $code->applies_to_storefront) {
                throw ValidationException::withMessages(['code' => 'That code does not apply to books.']);
            }

            if ($code->partner_id && $code->partner_id !== $partner->id) {
                throw ValidationException::withMessages(['code' => 'That code belongs to another partner.']);
            }

            $code->forceFill(['partner_id' => $partner->id])->save();

            AdminAudit::record('partner.code_attached', $partner, ['codes' => $previous], ['codes' => array_values(array_unique([...$previous, $code->code]))], $reason, $actor);
        });
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $fields
     */
    private function move(Partner $partner, array $from, string $to, string $action, string $reason, User $actor, array $fields): void
    {
        AdminAudit::transaction(function () use ($partner, $from, $to, $action, $reason, $actor, $fields) {
            $row = Partner::whereKey($partner->id)->lockForUpdate()->first();

            if (! in_array($row->status, $from, true)) {
                throw ValidationException::withMessages(['reason' => 'This partner is '.$row->status.'.']);
            }

            $row->forceFill(['status' => $to] + $fields)->save();

            AdminAudit::record($action, $row, ['status' => $partner->status], ['status' => $to], $reason, $actor);
        });

        $partner->refresh();
    }
}
