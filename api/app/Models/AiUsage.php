<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One call to an AI provider. Written by App\Platform\Ai\AiMeter only. */
class AiUsage extends Model
{
    protected $table = 'ai_usage';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'float',
            'simulated' => 'boolean',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'images' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
