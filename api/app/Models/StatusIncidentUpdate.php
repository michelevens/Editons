<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in an incident's timeline. */
class StatusIncidentUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(StatusIncident::class, 'status_incident_id');
    }
}
