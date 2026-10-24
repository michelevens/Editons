<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A "Report this book" from the public storefront (App\Platform\Trust\Moderation). */
class ContentReport extends Model
{
    /** category => what the reporter is shown. Keys are stored: never rename one. */
    public const CATEGORIES = [
        'copyright' => 'It copies somebody else’s work',
        'illegal' => 'Illegal content',
        'hate' => 'Hate or harassment',
        'sexual_minors' => 'Sexual content involving minors',
        'misleading' => 'Misleading or a scam',
        'other' => 'Something else',
    ];

    public const STATUSES = ['open', 'resolved', 'dismissed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
