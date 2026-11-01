<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One "Write my book", or one chapter written on request (ADR — AI writes
 * the book). Approved once by the author, run by App\Jobs\WriteBook one
 * chapter at a time; whatever finished is kept whatever stops it.
 */
class DraftRun extends Model
{
    public const ACTIVE = ['queued', 'running'];

    /** Stopped by the author or the run, but not over: resume picks it up. */
    public const RESUMABLE = ['paused', 'stopped', 'failed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'estimate' => 'array',
            'cost_usd' => 'decimal:6',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (DraftRun $run) => $run->uuid ??= (string) Str::uuid());
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

    public function chapters(): HasMany
    {
        return $this->hasMany(DraftChapter::class)->orderBy('position');
    }

    public function active(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
