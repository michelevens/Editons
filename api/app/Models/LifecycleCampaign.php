<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A triggered email (App\Growth\Lifecycle). */
class LifecycleCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'delay_days' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function sends(): HasMany
    {
        return $this->hasMany(LifecycleSend::class);
    }
}
