<?php

namespace App\Models;

use App\Editorial\EditorialAgent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One issue raised by an agent.
 *
 * A finding is a suggestion. Accepting it is a separate recorded act — the
 * manuscript is never rewritten without a human saying so (CLAUDE.md).
 */
class EditorialFinding extends Model
{
    use HasFactory;

    public const SEVERITIES = ['critical', 'warning', 'suggestion'];

    public const STATUSES = ['open', 'accepted', 'dismissed'];

    protected $fillable = [
        'editorial_review_id', 'manuscript_section_id', 'agent', 'severity',
        'title', 'detail', 'excerpt', 'suggestion', 'applied_version_id',
        'from_offset', 'to_offset', 'status', 'resolved_by', 'resolved_at',
        // Triage and "never flag this again" (ADR — Style guides and review options).
        'rule', 'term', 'source', 'dismissal',
    ];

    protected function casts(): array
    {
        return [
            'agent' => EditorialAgent::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(EditorialReview::class, 'editorial_review_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ManuscriptSection::class, 'manuscript_section_id');
    }

    /**
     * Is this finding still about text that exists?
     *
     * The document moves while reviews run. Comparing the stored excerpt
     * against the current text is what lets the panel mark a finding stale
     * rather than pointing an author at a sentence they already deleted.
     */
    public function isStale(string $currentText): bool
    {
        if (! $this->excerpt) {
            return false;
        }

        // The excerpt may have been clipped with an ellipsis; compare the
        // part that was stored verbatim.
        $needle = rtrim($this->excerpt, '…');

        return mb_strlen($needle) > 12 && ! str_contains($currentText, $needle);
    }
}
