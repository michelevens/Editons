<?php

namespace App\Models;

use App\Editorial\EditorialAgent;
use App\Platform\RecordsImpersonator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One run of one agent over one section. */
class EditorialReview extends Model
{
    use HasFactory, RecordsImpersonator;

    protected $fillable = [
        'manuscript_section_id', 'user_id', 'agent', 'status',
        'section_version', 'driver', 'model', 'input_tokens',
        'output_tokens', 'cost_usd', 'error', 'completed_at',
        'style_guide_version_id', 'style_digest', 'options',
    ];

    protected function casts(): array
    {
        return [
            'agent' => EditorialAgent::class,
            'completed_at' => 'datetime',
            'cost_usd' => 'decimal:6',
            'options' => 'array',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ManuscriptSection::class, 'manuscript_section_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(EditorialFinding::class);
    }
}
