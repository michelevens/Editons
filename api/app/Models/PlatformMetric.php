<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The platform's figures for one day (platform:snapshot-metrics). */
class PlatformMetric extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'figures' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
