<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Stripe event that has been acted on, for one purpose.
 *
 * Stripe delivers at least once and retries on any non-2xx, so the same
 * event can arrive twice — even twice at once. claim() is an atomic insert:
 * exactly one delivery wins it. If the work then fails, release() lets the
 * retry try again.
 */
class ProcessedWebhook extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public static function claim(string $eventId, string $purpose, string $type): bool
    {
        return self::query()->insertOrIgnore([
            'event_id' => $eventId,
            'purpose' => $purpose,
            'type' => $type,
            'created_at' => now(),
        ]) === 1;
    }

    public static function release(string $eventId, string $purpose): void
    {
        self::where('event_id', $eventId)->where('purpose', $purpose)->delete();
    }
}
