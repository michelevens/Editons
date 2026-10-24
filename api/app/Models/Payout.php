<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Royalties paid to an author outside Écrivain (a bank transfer, PayPal…),
 * recorded by the owner. Stripe Connect is not built, so this ledger is the
 * only record of what has been paid.
 */
class Payout extends Model
{
    public const METHODS = [
        'bank_transfer' => 'Bank transfer',
        'paypal' => 'PayPal',
        'wise' => 'Wise',
        'moncash' => 'MonCash',
        'cheque' => 'Cheque',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
