<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Why a subscription ended, as Stripe reported it. See App\Billing\SubscriptionSync. */
class SubscriptionCancellation extends Model
{
    /** Stripe's cancellation_details.feedback values, labelled. */
    public const FEEDBACK = [
        'too_expensive' => 'Too expensive',
        'missing_features' => 'Missing features',
        'switched_service' => 'Switched to another service',
        'unused' => 'Not using it enough',
        'customer_service' => 'Customer service',
        'too_complex' => 'Too complicated',
        'low_quality' => 'Quality was not good enough',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mrr_cents' => 'integer',
            'requested_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
