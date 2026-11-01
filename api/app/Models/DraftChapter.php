<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section of a draft run: its status, the summary the writer made of
 * it, and what it cost (ADR — AI writes the book).
 */
class DraftChapter extends Model
{
    public const STATUSES = ['queued', 'writing', 'done', 'skipped', 'failed', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DraftRun::class, 'draft_run_id');
    }

    /** The credit hold taken before the calls. */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ManuscriptSection::class, 'manuscript_section_id');
    }
}
