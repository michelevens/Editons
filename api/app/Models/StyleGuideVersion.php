<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One saved version of a style guide. Never changed once written: a save
 * is a new row (the database refuses an UPDATE too), and a review records
 * the version it used.
 */
class StyleGuideVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['version', 'rules', 'created_by'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Style guide versions are immutable; save a new version.'));
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(StyleGuide::class, 'style_guide_id');
    }
}
