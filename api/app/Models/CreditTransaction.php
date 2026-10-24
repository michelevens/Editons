<?php

namespace App\Models;

use App\Platform\RecordsImpersonator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the credit ledger.
 *
 * Append-only by intent: there is no update path, and nothing in the app
 * edits a row after it is written. A correction is another row.
 */
class CreditTransaction extends Model
{
    use HasFactory, RecordsImpersonator;

    public const KINDS = ['grant', 'spend', 'refund', 'purchase', 'expiry'];

    protected $fillable = [
        'user_id', 'amount', 'kind', 'label',
        'subject_type', 'subject_id', 'reverses_id', 'external_reference',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The spend this refund reverses, if it is one. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
