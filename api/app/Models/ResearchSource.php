<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A source in a book's research library.
 *
 * Carries its own verification standing. Nothing in the system treats a
 * source as evidence on the strength of its existence alone — see
 * ARCHITECTURE.md design rule 3 ("approved research; explicit provenance").
 */
class ResearchSource extends Model
{
    use HasFactory, SoftDeletes;

    public const KINDS = ['book', 'journal_article', 'website', 'report', 'interview', 'other'];

    public const VERIFICATIONS = ['unverified', 'verified', 'disputed'];

    protected $fillable = [
        'kind', 'title', 'authors', 'publisher', 'published',
        'container', 'volume', 'issue', 'pages', 'edition',
        'url', 'doi', 'isbn', 'notes',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function citations(): HasMany
    {
        return $this->hasMany(Citation::class);
    }

    /**
     * May this source be used to mark a claim supported?
     *
     * A disputed source explicitly may not: recording a dispute and then
     * letting the source go on backing claims would make the dispute
     * decorative.
     */
    public function isUsableAsEvidence(): bool
    {
        return $this->verification === 'verified';
    }

    /** A short human label: "Smith (2019)". */
    public function reference(): string
    {
        $who = $this->authors ?: $this->publisher;

        return match (true) {
            $who && $this->published => "{$who} ({$this->published})",
            (bool) $who => $who,
            (bool) $this->published => "{$this->title} ({$this->published})",
            default => $this->title,
        };
    }
}
