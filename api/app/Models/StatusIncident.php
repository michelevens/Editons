<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An incident on the public status page (App\Platform\Trust\StatusPage). */
class StatusIncident extends Model
{
    public const SEVERITIES = ['minor', 'major', 'critical', 'maintenance'];

    public const STATUSES = ['investigating', 'identified', 'monitoring', 'resolved'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'show_in_banner' => 'boolean',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function updates(): HasMany
    {
        return $this->hasMany(StatusIncidentUpdate::class)->orderByDesc('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
