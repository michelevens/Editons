<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a book's controlled vocabulary.
 *
 * Author-declared, never inferred. A machine deciding for itself which
 * spelling is canonical will eventually "fix" a deliberate one.
 */
class Term extends Model
{
    use HasFactory;

    protected $table = 'terminology';

    public const KINDS = ['character', 'place', 'term', 'organization', 'other'];

    protected $fillable = ['kind', 'canonical', 'variants', 'note'];

    protected function casts(): array
    {
        return ['variants' => 'array'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
