<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An Écrivain Partner: somebody who sells books on commission from a page
 * of their own (App\Commerce\Partners).
 *
 * Nothing is fillable: the application, the page editor and the console
 * each set the fields they own, from validated input.
 *
 * payout_details is encrypted with the app key. It is a free-text reference
 * (a PayPal address, a MonCash number, a bank-transfer note), never a tax id.
 */
class Partner extends Model
{
    public const STATUSES = ['applied', 'approved', 'declined', 'suspended'];

    /** How the applicant will promote. Keys are stored. */
    public const PROMOTION = [
        'school' => 'A school or classroom',
        'bookstore' => 'A bookstore',
        'social' => 'Social media',
        'community' => 'A community, church or association',
        'other' => 'Something else',
    ];

    /** Where a partner wants to be paid. Stripe Connect is not built. */
    public const PAYOUT_METHODS = [
        'paypal' => 'PayPal (email address)',
        'moncash' => 'MonCash (phone number)',
        'bank_transfer' => 'Bank transfer (a note for the transfer)',
    ];

    /** The page's chrome. Haitian Creole strings await a native review. */
    public const LOCALES = ['en', 'fr', 'ht'];

    protected $guarded = ['*'];

    protected $hidden = ['payout_details'];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'socials' => 'array',
            'restricted' => 'boolean',
            'payout_details' => 'encrypted',
            'agreement_accepted_at' => 'datetime',
            'handle_changed_at' => 'datetime',
            'payout_details_updated_at' => 'datetime',
            'tax_form_received_at' => 'datetime',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
            'suspended_at' => 'datetime',
            'balance_released_at' => 'datetime',
        ];
    }

    /** "K7Q2M9XA": short, stable, unambiguous on a printed flyer. */
    public static function newCode(): string
    {
        do {
            $code = strtr(strtoupper(Str::random(8)), ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'W', 'L' => 'V']);
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shelf(): HasMany
    {
        return $this->hasMany(PartnerShelfItem::class)->orderBy('position')->orderBy('id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(PartnerClick::class);
    }

    public function oldHandles(): HasMany
    {
        return $this->hasMany(PartnerHandle::class);
    }

    /** The sellers a restricted (invited) partner may sell. */
    public function sellers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'partner_sellers', 'partner_id', 'seller_id')->withTimestamps();
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** Suspended and not released by staff: nothing may be paid out. */
    public function balanceFrozen(): bool
    {
        return $this->isSuspended() && $this->balance_released_at === null;
    }

    public function pageUrl(): string
    {
        return route('partners.page', $this->handle);
    }
}
