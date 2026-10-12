<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A claim in the manuscript and the source backing it.
 *
 * The claim is stored in the author's words rather than derived from the
 * sentence, so that rewriting the prose does not quietly change what the
 * source is being held to have supported.
 */
class Citation extends Model
{
    use HasFactory;

    public const STATUSES = ['needs_source', 'supported', 'contradicted'];

    protected $fillable = [
        'manuscript_section_id', 'research_source_id', 'created_by',
        'claim', 'locator', 'excerpt', 'from_offset', 'to_offset', 'status',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(ManuscriptSection::class, 'manuscript_section_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ResearchSource::class, 'research_source_id');
    }

    /**
     * Is this citation resting on something the author has actually checked?
     *
     * A citation can be marked supported while its source is still
     * unverified — that is a normal intermediate state, not an error. But it
     * must be VISIBLE, because a bibliography that looks complete while
     * resting on unchecked sources is worse than one that is obviously
     * unfinished.
     */
    public function restsOnUnverifiedSource(): bool
    {
        return $this->status === 'supported'
            && $this->source !== null
            && ! $this->source->isUsableAsEvidence();
    }

    /** Same drift problem as findings: has the cited text gone? */
    public function isStale(string $currentText): bool
    {
        if (! $this->excerpt) {
            return false;
        }

        return ! str_contains($currentText, $this->excerpt);
    }
}
