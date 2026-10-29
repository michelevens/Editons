<?php

namespace App\Models;

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued review run over a range or the whole book
 * (App\Jobs\RunEditorialReview). Its sections are reviewed one at a time;
 * done_ids is what has been reviewed (and billed), so a retried job never
 * reviews a section twice.
 */
class EditorialRun extends Model
{
    public const ACTIVE = ['queued', 'running'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'agent' => EditorialAgent::class,
            'section_ids' => 'array',
            'done_ids' => 'array',
            'context' => 'array',
            'estimate' => 'array',
            'cost_usd' => 'decimal:6',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function active(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** The options frozen when the run was queued. */
    public function reviewContext(): ReviewContext
    {
        $c = $this->context ?? [];

        return new ReviewContext(
            style: $c['style'] ?? null,
            focus: $c['focus'] ?? null,
            sensitivity: $c['sensitivity'] ?? 'standard',
            disabledRules: $c['disabled_rules'] ?? [],
            styleVersionId: $c['style_version_id'] ?? null,
        );
    }

    public static function freeze(ReviewContext $context): array
    {
        return [
            'style' => $context->style,
            'focus' => $context->focus,
            'sensitivity' => $context->sensitivity,
            'disabled_rules' => $context->disabledRules,
            'style_version_id' => $context->styleVersionId,
        ];
    }

    /** What the studio polls. */
    public function progress(): array
    {
        return [
            'id' => $this->uuid,
            'agent' => $this->agent->value,
            'scope' => $this->scope,
            'status' => $this->status,
            'done' => count($this->done_ids ?? []),
            'total' => $this->total,
            'findings' => $this->findings,
            'failed' => $this->failed,
            'credits_spent' => $this->credits_spent,
            'cost_usd' => (float) $this->cost_usd,
            'stop_reason' => $this->stop_reason,
            'estimate' => $this->estimate,
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
