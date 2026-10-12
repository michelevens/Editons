<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of a section (ADR-0005).
 *
 * Rows are written once and never changed. There is no SoftDeletes trait and
 * no updated_at column, and that absence is the point: "immutable versions"
 * and "rollback" are named requirements, and history that can be edited is
 * not history.
 */
class ManuscriptVersion extends Model
{
    use HasFactory;

    public const REASONS = ['autosave', 'manual', 'import', 'restore'];

    /** created_at only — see the class docblock. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'manuscript_section_id', 'user_id', 'version_number',
        'title', 'content', 'word_count', 'reason', 'label',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'word_count' => 'integer',
            'version_number' => 'integer',
        ];
    }

    /**
     * Block updates and deletes at the model layer.
     *
     * The database does not enforce immutability — there is no trigger — so
     * this is the guard. Belt and braces for a rule that is easy to violate
     * accidentally from a seeder or a console command.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException(
            'Manuscript versions are immutable; create a new version instead.'
        ));

        static::deleting(fn () => throw new \LogicException(
            'Manuscript versions cannot be deleted. Retention policy is owed at Milestone 6.'
        ));
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ManuscriptSection::class, 'manuscript_section_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
