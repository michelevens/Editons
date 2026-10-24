<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account's module forced on or off. Written by App\Platform\Features only. */
class FeatureOverride extends Model
{
    protected $fillable = ['user_id', 'feature', 'enabled', 'reason', 'set_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
