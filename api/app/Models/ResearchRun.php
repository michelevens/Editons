<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI research run: the question, the model, and the sources it actually
 * retrieved (ADR — AI research, source-backed fact-checking, cover
 * templates). A proposal: nothing here is in the library until the author
 * saves it. Written by App\Research\Assistant\ResearchAssistant only.
 */
class ResearchRun extends Model
{
    public const MODES = ['topic', 'chapter', 'plan', 'claim'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'simulated' => 'boolean',
            'cost_usd' => 'float',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ManuscriptSection::class, 'manuscript_section_id');
    }
}
