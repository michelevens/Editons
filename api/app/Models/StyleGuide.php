<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A named editorial style an account keeps and its books follow
 * (App\Editorial\Style\StyleGuides). user_id is the owning account — an
 * author's, or a house account's — set by the service, never from input.
 * `version` is the latest saved version's number; the rules themselves
 * live on the immutable versions.
 */
class StyleGuide extends Model
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StyleGuideVersion::class)->orderByDesc('version');
    }

    /** The version books follow: the latest. */
    public function current(): HasOne
    {
        return $this->hasOne(StyleGuideVersion::class)->ofMany('version', 'max');
    }

    /** The books that follow it. */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
