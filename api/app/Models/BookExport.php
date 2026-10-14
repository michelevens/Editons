<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated file.
 *
 * Rows are never updated in place once ready — regenerating appends a new one.
 * That is what makes export history meaningful: it says what the book looked
 * like each time a file was produced, which is exactly the question an author
 * asks when a store rejects an upload.
 */
class BookExport extends Model
{
    use HasFactory;

    public const STATUSES = ['generating', 'ready', 'failed'];

    /** format => [label, extension, description]. */
    public const FORMATS = [
        'epub' => ['EPUB', 'epub', 'For Kindle, Apple Books, Kobo and most stores'],
        'pdf_print' => ['Print PDF', 'pdf', 'Press-ready interior at your trim size'],
        'docx' => ['Word document', 'docx', 'For editors and co-authors who mark up in Word'],
        'md' => ['Markdown', 'md', 'Plain text with structure preserved'],
        'html' => ['HTML', 'html', 'A single file for the web'],
    ];

    protected $fillable = ['format', 'status', 'word_count', 'byte_size', 'path', 'user_id'];

    protected function casts(): array
    {
        return [
            'word_count' => 'integer',
            'byte_size' => 'integer',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function label(): string
    {
        return self::FORMATS[$this->format][0] ?? $this->format;
    }

    public function extension(): string
    {
        return self::FORMATS[$this->format][1] ?? 'bin';
    }
}
