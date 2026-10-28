<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An export profile an author saved on a book: a name and the options
 * (App\Publishing\ExportOptions, normalised, without the name). book_id
 * and user_id are set by the controller from the book it authorised.
 */
class ExportProfile extends Model
{
    protected $fillable = ['name', 'options'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
