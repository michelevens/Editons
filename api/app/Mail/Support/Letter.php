<?php

namespace App\Mail\Support;

use App\Models\Book;
use App\Publishing\AccessibilityAudit;
use App\Publishing\CoverRenderer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What one email says, slot by slot.
 *
 * Every Écrivain email has the same anatomy, in a fixed order: preheader,
 * header subtitle, eyebrow, headline, lede, content blocks, one button, at
 * most one callout, footer. A Mailable fills the slots; the HTML layout
 * (emails/standard) and the plain-text layout (emails/text/standard) both
 * render the same Letter, so the two versions cannot drift apart.
 *
 * Every value is plain text. Nothing here is ever rendered unescaped in the
 * HTML version — a book title is author input, and an author can type
 * `<script>` into a title as easily as anything else.
 *
 * Severity drives the strip under the header band, the eyebrow colour and
 * nothing else. Never the button: the button is always the brand colour, so
 * an urgent email does not look like a different product.
 */
final class Letter
{
    public const SEVERITIES = ['action', 'confirmed', 'heads', 'urgent', 'info'];

    public string $preheader = '';

    public string $subtitle = '';

    public ?string $eyebrow = null;

    public string $headline = '';

    public ?string $lede = null;

    public string $severity = 'info';

    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    /** @var array{label: string, url: string}|null */
    public ?array $cta = null;

    /** Print the button's URL underneath it, for clients that strip buttons. */
    public bool $showFallbackUrl = false;

    /** @var array{label: string, url: string}|null */
    public ?array $secondary = null;

    /** @var array{tone: string, title: ?string, body: string}|null */
    public ?array $callout = null;

    /** One line in the footer saying why this arrived. */
    public ?string $reason = null;

    private function __construct(public string $subject) {}

    public static function make(string $subject): self
    {
        return new self($subject);
    }

    public function preheader(string $text): self
    {
        $this->preheader = $text;

        return $this;
    }

    public function subtitle(string $text): self
    {
        $this->subtitle = $text;

        return $this;
    }

    public function eyebrow(?string $text): self
    {
        $this->eyebrow = $text;

        return $this;
    }

    public function headline(string $text): self
    {
        $this->headline = $text;

        return $this;
    }

    public function lede(?string $text): self
    {
        $this->lede = $text;

        return $this;
    }

    public function severity(string $severity): self
    {
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Unknown email severity [{$severity}].");
        }

        $this->severity = $severity;

        return $this;
    }

    /** A paragraph of body copy. */
    public function paragraph(?string $text): self
    {
        if ($text !== null && $text !== '') {
            $this->blocks[] = ['type' => 'paragraph', 'text' => $text];
        }

        return $this;
    }

    /**
     * Label/value rows, as a two-cell table. Empty values are dropped, so a
     * Mailable can pass an optional fact without wrapping it in a condition.
     *
     * @param  array<string, scalar|null>  $rows
     */
    public function facts(array $rows, ?string $title = null): self
    {
        $rows = array_filter($rows, fn ($value) => $value !== null && $value !== '');

        if ($rows !== []) {
            $this->blocks[] = [
                'type' => 'facts',
                'title' => $title,
                'rows' => array_map('strval', $rows),
            ];
        }

        return $this;
    }

    /**
     * Big numbers side by side.
     *
     * @param  list<array{label: string, value: scalar, tone?: string}>  $items
     */
    public function stats(array $items): self
    {
        if ($items !== []) {
            $this->blocks[] = ['type' => 'stats', 'items' => $items];
        }

        return $this;
    }

    /**
     * A list of items, each with an optional detail line and status badge.
     *
     * @param  list<array{title: string, detail?: ?string, badge?: ?string, tone?: ?string, url?: ?string}>  $items
     */
    public function items(array $items, ?string $title = null, ?string $empty = null): self
    {
        if ($items !== [] || $empty !== null) {
            $this->blocks[] = [
                'type' => 'items',
                'title' => $title,
                'items' => $items,
                'empty' => $empty,
            ];
        }

        return $this;
    }

    /**
     * A table with columns, for receipts and statements. The last column is
     * right-aligned (it is always an amount); `total` is a bold closing row.
     *
     * @param  list<string>  $columns
     * @param  list<list<scalar|null>>  $rows
     * @param  list<scalar|null>|null  $total
     */
    public function table(array $columns, array $rows, ?array $total = null, ?string $title = null): self
    {
        $this->blocks[] = [
            'type' => 'table',
            'title' => $title,
            'columns' => $columns,
            'rows' => $rows,
            'total' => $total,
        ];

        return $this;
    }

    /** Somebody's words, quoted — a comment, a passage. */
    public function quote(string $text, ?string $cite = null): self
    {
        $this->blocks[] = ['type' => 'quote', 'text' => $text, 'cite' => $cite];

        return $this;
    }

    /**
     * The book this email is about, as a small typographic cover.
     *
     * Drawn from the cover's palette rather than an image: the rendered
     * cover is served behind sign-in (the bucket is private), so an <img>
     * would be a broken box in every inbox. Colour and type survive image
     * blocking, Outlook and dark mode; a picture of a cover would not.
     */
    public function book(Book $book, ?string $caption = null): self
    {
        $palettes = AccessibilityAudit::PALETTES;
        $cover = $book->cover ?? [];
        $palette = $palettes[$cover['palette'] ?? 0] ?? $palettes[0];

        $this->blocks[] = [
            'type' => 'book',
            'title' => (string) ($cover['title'] ?? $book->title),
            'subtitle' => $book->subtitle,
            'author' => (string) ($cover['author'] ?? $book->author_name ?? ''),
            'caption' => $caption,
            'from' => $palette['from'],
            'ink' => $palette['ink'],
            'cover_path' => self::coverThumb($book),
        ];

        return $this;
    }

    /**
     * The book's real cover, as the 320px thumbnail on the files disk, for
     * the card to embed in the message itself (cid:, not a link: the cover
     * is served behind sign-in, and a linked image is a broken box in most
     * inboxes). Null when it cannot be drawn, and the card falls back to the
     * typographic cover: an email is never held up by its picture.
     */
    private static function coverThumb(Book $book): ?string
    {
        if (! $book->exists) {
            return null;
        }

        try {
            return app(CoverRenderer::class)->ensureThumb($book);
        } catch (Throwable $e) {
            Log::notice('Email cover thumbnail unavailable', ['book' => $book->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** A rule between groups of blocks. */
    public function divider(): self
    {
        $this->blocks[] = ['type' => 'divider'];

        return $this;
    }

    /** The one button. A second action is a text link — see secondary(). */
    public function cta(string $label, string $url, bool $showFallbackUrl = false): self
    {
        $this->cta = ['label' => $label, 'url' => $url];
        $this->showFallbackUrl = $showFallbackUrl;

        return $this;
    }

    public function secondary(string $label, string $url): self
    {
        $this->secondary = ['label' => $label, 'url' => $url];

        return $this;
    }

    /** At most one, for a caveat the facts cannot carry. */
    public function callout(string $tone, string $body, ?string $title = null): self
    {
        if (! in_array($tone, ['info', 'success', 'warning', 'danger'], true)) {
            throw new \InvalidArgumentException("Unknown callout tone [{$tone}].");
        }

        $this->callout = ['tone' => $tone, 'title' => $title, 'body' => $body];

        return $this;
    }

    public function reason(?string $text): self
    {
        $this->reason = $text;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
