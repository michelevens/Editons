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

    /**
     * 'expired' is a row that was ready once and whose file is no longer in
     * storage: still history, no longer a download.
     */
    public const STATUSES = ['generating', 'ready', 'failed', 'expired'];

    /**
     * Seconds a 'generating' row may go without news before it is failed.
     *
     * The job's own timeout plus a margin for the worker to notice: a row
     * older than this belongs to a process that died without saying so.
     * It is longer than the worker's --timeout (1900 s), so a render still
     * running has been stopped by the worker before its row is called dead.
     */
    public const STALE_AFTER = 1800 + 300;

    /** format => [label, extension, description]. */
    public const FORMATS = [
        'epub' => ['EPUB', 'epub', 'For Kindle, Apple Books, Kobo and most stores'],
        'pdf' => ['PDF', 'pdf', 'A real PDF, paginated at your trim size'],
        /*
         * Print-ready HTML, not a PDF.
         *
         * This was labelled "Print PDF" with a .pdf extension and produced
         * an HTML file: no PDF library is installed and printable() emits
         * HTML with @page CSS. The file would not open in any PDF reader,
         * which is worse than not offering the format.
         *
         * The output is genuinely useful — paginated at the chosen trim
         * size, ready for a browser's Print to PDF — so it is kept and
         * named for what it is. A real PDF renderer is a dependency
         * decision, and when one is added this becomes 'pdf' again.
         */
        'pdf_print' => ['Print-ready HTML', 'html', 'Paginated at your trim size — print to PDF from a browser'],
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
