<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ExportProfile;
use App\Models\ManuscriptSection;
use Illuminate\Validation\ValidationException;

/**
 * What one export is made with: an export profile (ADR-0043 — Export
 * profiles).
 *
 * A profile narrows or marks the book rather than designing it again: a
 * chapter range ("first 2 chapters", or from one section to another), the
 * cover on or off, front and back matter left out by their Anatomy keys, a
 * watermark (drawn diagonally on every PDF page; the other formats get a
 * notice page instead), standard manuscript format (PDF and Word only),
 * where the notes go and how deep the contents runs. Four presets ship
 * (Full book, Review copy, Sample, Manuscript submission) and an author can
 * save their own per book (ExportProfile).
 *
 * Every renderer reads the book through Anatomy::plan, so the range and the
 * matter are applied there, once, while an export is running (within()):
 * the contents, the numbering, the EPUB spine and the Word sections all
 * follow without a renderer of their own. Full book with nothing changed
 * is today's output exactly, and is stored as no profile at all, so an
 * export made before profiles and one made after read the same.
 *
 * The book's own per-format matter set (E3: say, no "Also by" page in the
 * EPUB) is not a profile: it applies to every export of the book, Full book
 * included, since the file a store receives is a Full-book one. It is kept
 * in books.meta.export_matter and defaults to nothing left out.
 */
final class ExportOptions
{
    public const PRESETS = ['full', 'review', 'sample', 'manuscript'];

    /** The keys a profile may carry; anything else is refused. */
    public const KEYS = ['preset', 'id', 'name', 'range', 'cover', 'exclude', 'watermark', 'opacity', 'manuscript', 'manuscript_font', 'contact', 'notes', 'toc_depth', 'closing'];

    public const NOTES = ['chapter', 'book', 'page'];

    public const MANUSCRIPT_FONTS = ['times', 'courier'];

    /** Formats each option means something in. */
    public const MANUSCRIPT_FORMATS = ['pdf', 'docx'];

    /** Formats a profile applies to; a print cover is for one service, never a range. */
    public const FORMATS = ['pdf', 'pdf_interior', 'pdf_print', 'epub', 'docx', 'html', 'md'];

    /** Formats that draw the watermark on the page rather than adding a notice page. */
    public const WATERMARKED = ['pdf', 'pdf_interior'];

    /** Front and back matter keys pseudo-named for sections nobody named. */
    public const UNNAMED = ['other_front', 'other_back'];

    public const WATERMARK_MAX = 80;

    public const CONTACT_MAX = 300;

    /** Why page-foot notes are not offered: said on screen and by the API. */
    public const PAGE_NOTES_REASON = 'Notes at the foot of the page need a renderer that measures the page; the PDF renderer does not, so notes go at the end of each chapter or of the book. Word documents already use Word’s own footnotes.';

    /** The book's furniture for the pages a profile adds, in its language. */
    private const WORDS = [
        'en' => ['continue' => 'Continue reading', 'body' => 'You have reached the end of this sample of :title.', 'link' => 'Get the full book', 'notes' => 'Notes', 'review' => 'Review copy'],
        'fr' => ['continue' => 'Lire la suite', 'body' => 'Vous avez atteint la fin de cet extrait de :title.', 'link' => 'Obtenir le livre complet', 'notes' => 'Notes', 'review' => 'Exemplaire de presse'],
        'ht' => ['continue' => 'Kontinye li', 'body' => 'Ou rive nan bout ekstrè :title la.', 'link' => 'Jwenn liv la an antye', 'notes' => 'Nòt', 'review' => 'Kopi pou revizyon'],
        'es' => ['continue' => 'Seguir leyendo', 'body' => 'Has llegado al final de este extracto de :title.', 'link' => 'Consigue el libro completo', 'notes' => 'Notas', 'review' => 'Ejemplar de prensa'],
        'de' => ['continue' => 'Weiterlesen', 'body' => 'Sie haben das Ende dieser Leseprobe von :title erreicht.', 'link' => 'Das ganze Buch', 'notes' => 'Anmerkungen', 'review' => 'Rezensionsexemplar'],
        'pt' => ['continue' => 'Continuar a ler', 'body' => 'Chegou ao fim deste excerto de :title.', 'link' => 'Obtenha o livro completo', 'notes' => 'Notas', 'review' => 'Exemplar de imprensa'],
    ];

    /** The export now running: [book id, format, options], or null. */
    private static ?array $active = null;

    /** @param  array<string, mixed>  $values  normalised (normalise()) */
    private function __construct(public readonly array $values) {}

    /** Everything at its default: Full book, today's output. */
    public static function defaults(): array
    {
        return [
            'preset' => 'full', 'id' => null, 'name' => null, 'range' => null, 'cover' => true, 'exclude' => [],
            'watermark' => null, 'opacity' => 0.15, 'manuscript' => false, 'manuscript_font' => 'times',
            'contact' => null, 'notes' => 'chapter', 'toc_depth' => null, 'closing' => false,
        ];
    }

    /**
     * A built-in preset's values.
     *
     * Sample keeps the front matter a reader expects in a sample (the title
     * page, the copyright page, the contents) and drops the rest, all the
     * back matter, and everything after chapter 2, then closes on a
     * "Continue reading" page. Manuscript submission is the text alone in
     * standard manuscript format.
     */
    public static function preset(string $key): array
    {
        $front = self::matterKeys('front');
        $back = self::matterKeys('back');

        return array_merge(self::defaults(), match ($key) {
            'review' => ['preset' => 'review', 'watermark' => 'Review copy — not for sale'],
            'sample' => ['preset' => 'sample', 'range' => ['first' => 2],
                'exclude' => array_values(array_diff([...$front, ...$back], ['title_page', 'copyright_page', 'table_of_contents'])),
                'closing' => true],
            'manuscript' => ['preset' => 'manuscript', 'manuscript' => true, 'cover' => false,
                'exclude' => [...$front, ...$back]],
            default => [],
        });
    }

    /** @return list<array{key: string, name: string, summary: string, options: array<string, mixed>}> */
    public static function presets(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'name' => self::presetName($key),
            'summary' => self::summary(self::preset($key)),
            'options' => self::preset($key),
        ], self::PRESETS);
    }

    public static function presetName(string $key): string
    {
        return match ($key) {
            'review' => 'Review copy',
            'sample' => 'Sample',
            'manuscript' => 'Manuscript submission',
            'custom' => 'Custom',
            default => 'Full book',
        };
    }

    /**
     * Front or back matter keys a profile may leave out: every Anatomy part
     * of that group, and the pseudo-key for sections nobody named.
     *
     * @return list<string>
     */
    public static function matterKeys(?string $group = null): array
    {
        $keys = [];

        foreach (BookPart::cases() as $part) {
            if (in_array($part->group(), ['front', 'back'], true) && ($group === null || $part->group() === $group)) {
                $keys[] = $part->value;
            }
        }

        if ($group !== 'back') {
            $keys[] = 'other_front';
        }

        if ($group !== 'front') {
            $keys[] = 'other_back';
        }

        return $keys;
    }

    /**
     * Profile input, checked against the book and normalised.
     *
     * Starts from a preset (Full book when none is named), or from a saved
     * profile of this book when an id is given, then applies what was
     * sent. Refuses unknown keys, a range outside the book's text, a
     * watermark longer than 80 characters, manuscript format for a format
     * other than PDF or Word, and page-foot notes.
     *
     * @param  array<string, mixed>  $input
     */
    public static function validate(array $input, Book $book, ?string $format = null): self
    {
        $fail = fn (string $key, string $message) => throw ValidationException::withMessages(["profile.{$key}" => $message]);

        if ($unknown = array_diff(array_keys($input), self::KEYS)) {
            $fail(reset($unknown), 'There is no export option called "'.reset($unknown).'".');
        }

        $base = self::defaults();

        if (($input['id'] ?? null) !== null) {
            $saved = ExportProfile::where('book_id', $book->id)->find((int) $input['id']);
            $saved ?? $fail('id', 'That export profile is not one of this book’s.');
            $base = array_merge(self::defaults(), $saved->options ?? [], ['id' => $saved->id, 'name' => $saved->name, 'preset' => 'custom']);
        } elseif (isset($input['preset'])) {
            in_array($input['preset'], [...self::PRESETS, 'custom'], true) || $fail('preset', 'There is no export preset called "'.$input['preset'].'".');
            $base = self::preset($input['preset'] === 'custom' ? 'full' : $input['preset']);
            $base['preset'] = $input['preset'];
        }

        $values = array_merge($base, array_diff_key($input, array_flip(['id', 'preset'])));

        // Each value, in its own shape.
        if (! is_bool($values['cover'])) {
            $fail('cover', 'Say whether to include the cover.');
        }

        foreach (['manuscript', 'closing'] as $flag) {
            is_bool($values[$flag]) || $fail($flag, 'Choose on or off.');
        }

        is_array($values['exclude']) || $fail('exclude', 'List the pages to leave out.');
        $values['exclude'] = array_values(array_unique($values['exclude']));

        if ($bad = array_diff($values['exclude'], self::matterKeys())) {
            $fail('exclude', '"'.reset($bad).'" is not front or back matter.');
        }

        if ($values['watermark'] !== null) {
            $values['watermark'] = trim((string) $values['watermark']);

            if (mb_strlen($values['watermark']) > self::WATERMARK_MAX) {
                $fail('watermark', 'Keep the watermark to '.self::WATERMARK_MAX.' characters.');
            }

            $values['watermark'] = $values['watermark'] === '' ? null : $values['watermark'];
        }

        if (! is_numeric($values['opacity']) || $values['opacity'] < 0.05 || $values['opacity'] > 0.6) {
            $fail('opacity', 'Choose an opacity from 5 % to 60 %.');
        }

        $values['opacity'] = round((float) $values['opacity'], 2);

        in_array($values['manuscript_font'], self::MANUSCRIPT_FONTS, true) || $fail('manuscript_font', 'Choose Times-like or Courier-like.');

        if ($values['contact'] !== null) {
            $values['contact'] = trim((string) $values['contact']);
            mb_strlen($values['contact']) <= self::CONTACT_MAX || $fail('contact', 'Keep the contact block to '.self::CONTACT_MAX.' characters.');
            $values['contact'] = $values['contact'] === '' ? null : $values['contact'];
        }

        in_array($values['notes'], self::NOTES, true) || $fail('notes', 'Notes go at the end of each chapter or of the book.');
        $values['notes'] === 'page' && $fail('notes', self::PAGE_NOTES_REASON);

        if ($values['toc_depth'] !== null && (! in_array($values['toc_depth'], [1, 2, 3, '1', '2', '3'], true))) {
            $fail('toc_depth', 'The contents goes 1, 2 or 3 levels deep.');
        }

        $values['toc_depth'] = $values['toc_depth'] === null ? null : (int) $values['toc_depth'];

        if ($values['name'] !== null) {
            $values['name'] = mb_substr(trim((string) $values['name']), 0, 60) ?: null;
        }

        $values['range'] = self::checkRange($values['range'], $book, $fail);

        // A setting that belongs to an option left off means nothing.
        if ($values['watermark'] === null) {
            $values['opacity'] = self::defaults()['opacity'];
        }

        if (! $values['manuscript']) {
            $values['manuscript_font'] = self::defaults()['manuscript_font'];
            $values['contact'] = null;
        }

        if ($format !== null) {
            in_array($format, self::FORMATS, true) || self::isDefaultValues($values)
                || $fail('preset', 'A print cover is made for the whole book; export profiles do not apply to it.');

            if ($values['manuscript'] && ! in_array($format, self::MANUSCRIPT_FORMATS, true)) {
                $fail('manuscript', 'Standard manuscript format is a PDF or a Word document.');
            }
        }

        // A preset changed by hand is a custom profile. (The chapters a
        // range covers are recorded with it, not chosen.)
        $chosen = fn (array $v) => ['range' => is_array($v['range']) ? array_diff_key($v['range'], ['chapters' => 0]) : null] + array_diff_key($v, array_flip(['preset', 'name']));

        if (in_array($values['preset'], self::PRESETS, true) && $values['id'] === null
            && $chosen($values) != $chosen(self::preset($values['preset']))) {
            $values['preset'] = 'custom';
        }

        return new self($values);
    }

    /**
     * A range is the first N chapters, or from one section of the text to
     * another (both included). Either way it is checked against the book
     * now, and the chapters it covers are recorded with it, so the export's
     * label still says "ch. 1–2" after the book changes.
     */
    private static function checkRange(mixed $range, Book $book, callable $fail): ?array
    {
        if ($range === null) {
            return null;
        }

        is_array($range) || $fail('range', 'Choose the chapters to export.');

        if ($unknown = array_diff(array_keys($range), ['first', 'from', 'to', 'chapters'])) {
            $fail('range', 'There is no range option called "'.reset($unknown).'".');
        }

        $chapters = self::chapterIds($book);
        $total = count($chapters);

        if (isset($range['first'])) {
            $first = filter_var($range['first'], FILTER_VALIDATE_INT);
            ($first !== false && $first >= 1) || $fail('range', 'Export at least one chapter.');
            $first <= $total || $fail('range', "This book has {$total} chapter".($total === 1 ? '' : 's').'.');

            return ['first' => $first, 'chapters' => [1, $first]];
        }

        $from = filter_var($range['from'] ?? null, FILTER_VALIDATE_INT);
        $to = filter_var($range['to'] ?? null, FILTER_VALIDATE_INT);
        ($from !== false && $to !== false) || $fail('range', 'Choose where the range starts and ends.');

        $body = self::bodySectionIds($book);
        $a = array_search($from, $body, true);
        $b = array_search($to, $body, true);

        ($a !== false && $b !== false) || $fail('range', 'Choose sections from this book’s text.');
        $a <= $b || $fail('range', 'The range ends before it starts.');

        // The chapters the window covers, by number.
        $inside = array_values(array_intersect($chapters, array_slice($body, $a, $b - $a + 1)));
        $inside !== [] || $fail('range', 'That range holds no chapter.');
        $numbers = array_map(fn (int $id) => array_search($id, $chapters, true) + 1, [$inside[0], end($inside)]);

        return ['from' => $from, 'to' => $to, 'chapters' => $numbers];
    }

    /** The book's chapters with text, in the printed order. @return list<int> */
    public static function chapterIds(Book $book): array
    {
        $types = $book->sections()->pluck('type', 'id');

        return collect(Anatomy::plan($book)['pages'])
            ->filter(fn ($p) => $p['group'] === 'body' && $p['section_id'] !== null && $p['state'] === 'ready'
                && ($types[$p['section_id']] ?? null) === 'chapter')
            ->pluck('section_id')->values()->all();
    }

    /** Every section of the text (parts, chapters and their sections), in order. @return list<int> */
    public static function bodySectionIds(Book $book): array
    {
        return collect(Anatomy::plan($book)['pages'])
            ->filter(fn ($p) => $p['group'] === 'body' && $p['section_id'] !== null)
            ->pluck('section_id')->values()->all();
    }

    /** A stored export's options. */
    public static function fromExport(BookExport $export): self
    {
        return new self(array_merge(self::defaults(), $export->options['profile'] ?? []));
    }

    public static function full(): self
    {
        return new self(self::defaults());
    }

    /** Full book with nothing changed: stored as no profile, and today's output. */
    public function isDefault(): bool
    {
        return self::isDefaultValues($this->values);
    }

    private static function isDefaultValues(array $values): bool
    {
        $ignore = array_flip(['preset', 'id', 'name']);

        return array_diff_key($values, $ignore) == array_diff_key(self::defaults(), $ignore);
    }

    /** What BookExport.options records, or null for Full book. */
    public function toStored(): ?array
    {
        return $this->isDefault() ? null : $this->values;
    }

    /** "Sample · ch. 1–2", "Review copy", "Pitch pack" (a saved profile). */
    public function label(): string
    {
        $name = $this->values['name'] ?: self::presetName($this->values['preset']);
        $chapters = $this->values['range']['chapters'] ?? null;

        return $name.($chapters ? ' · ch. '.($chapters[0] === $chapters[1] ? $chapters[0] : $chapters[0].'–'.$chapters[1]) : '');
    }

    /** The options in a line, for the Customise panel and the presets. */
    public static function summary(array $values): string
    {
        $values = array_merge(self::defaults(), $values);
        $parts = [];

        $parts[] = match (true) {
            isset($values['range']['first']) => 'First '.$values['range']['first'].' chapter'.($values['range']['first'] === 1 ? '' : 's'),
            isset($values['range']['chapters']) => 'Chapters '.$values['range']['chapters'][0].'–'.$values['range']['chapters'][1],
            default => 'Whole book',
        };

        if ($values['manuscript']) {
            $parts[] = 'manuscript format';
        }

        if (! $values['cover']) {
            $parts[] = 'no cover';
        }

        if ($values['exclude'] !== []) {
            $parts[] = count($values['exclude']).' kind'.(count($values['exclude']) === 1 ? '' : 's').' of front or back matter left out';
        }

        if ($values['watermark']) {
            $parts[] = 'watermark “'.$values['watermark'].'”';
        }

        if ($values['notes'] === 'book') {
            $parts[] = 'notes at the end of the book';
        }

        if ($values['toc_depth']) {
            $parts[] = 'contents '.$values['toc_depth'].' level'.($values['toc_depth'] === 1 ? '' : 's');
        }

        if ($values['closing']) {
            $parts[] = '“Continue reading” page';
        }

        return implode(' · ', $parts);
    }

    // --- The export running now ---------------------------------------------

    /**
     * Run $fn with these options in force for $book in $format: Anatomy::
     * plan narrows and marks the book for it. Always cleared after, so a
     * worker's next export, or a preview in the same request, reads the
     * book whole.
     */
    public static function within(Book $book, string $format, ?self $options, callable $fn): mixed
    {
        $previous = self::$active;
        self::$active = ['book' => $book->id, 'format' => $format, 'options' => $options ?? self::full()];

        try {
            return $fn();
        } finally {
            self::$active = $previous;
        }
    }

    /** The options in force for this book now, if an export of it is running. */
    public static function active(?Book $book = null): ?self
    {
        return self::$active && ($book === null || self::$active['book'] === $book->id) ? self::$active['options'] : null;
    }

    public static function activeFormat(): ?string
    {
        return self::$active['format'] ?? null;
    }

    /**
     * Anatomy::plan's pages for the export running now, before they are
     * paginated: the book's matter set for this format and the profile's
     * own exclusions taken out, the text narrowed to the range, and the
     * pages the profile adds (the review notice, the notes at the end of
     * the book, the "Continue reading" page) put in.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    public static function filterPages(Book $book, array $pages, string $language): array
    {
        $options = self::active($book);

        if ($options === null) {
            return $pages;
        }

        $format = (string) self::activeFormat();
        $values = $options->values;
        $exclude = [...self::bookMatter($book, $format), ...$values['exclude']];

        if ($exclude !== []) {
            $pages = array_values(array_filter($pages, fn (array $page) => ! in_array(self::matterKey($page), $exclude, true)));
        }

        if ($values['range'] !== null) {
            $pages = self::narrow($book, $pages, $values['range']);
        }

        $words = self::words($language);

        if ($values['watermark'] !== null && ! in_array($format, self::WATERMARKED, true)) {
            $notice = self::page('review_notice', $words['review'], 'front', ['text' => $values['watermark']]);
            $at = collect($pages)->search(fn ($p) => $p['part'] === 'title_page');
            array_splice($pages, $at === false ? 0 : $at + 1, 0, [$notice]);
        }

        if ($values['notes'] === 'book' && in_array($format, ['pdf', 'pdf_interior', 'pdf_print', 'epub'], true) && self::hasNotes($book, $pages)) {
            $pages[] = self::page('book_notes', $words['notes'], 'back', ['heading' => $words['notes']]);
        }

        if ($values['closing']) {
            $product = $book->product;
            $url = $product && $product->isPublished() ? route('store.product', $product->slug) : null;

            $pages[] = self::page('continue_reading', $words['continue'], 'back', [
                'heading' => $words['continue'],
                'text' => str_replace(':title', $book->title, $words['body']),
                'link' => $url ? $words['link'] : null,
                'url' => $url,
            ]);
        }

        return $pages;
    }

    /**
     * The book's own matter set for a format family (E3): what every
     * export of it leaves out, Full book included. Empty by default.
     *
     * @return list<string>
     */
    public static function bookMatter(Book $book, string $format): array
    {
        $set = ($book->meta ?? [])['export_matter'] ?? [];

        return array_values(array_intersect((array) ($set[self::family($format)] ?? []), self::matterKeys()));
    }

    /** ebook (EPUB) or print (the PDFs, Word, HTML and Markdown). */
    public static function family(string $format): string
    {
        return $format === 'epub' ? 'ebook' : 'print';
    }

    /** A page's matter key: its Anatomy part, or other_front / other_back for an unnamed one. */
    private static function matterKey(array $page): ?string
    {
        if ($page['part'] !== null) {
            return $page['part'];
        }

        return match ($page['group']) {
            'front' => 'other_front',
            'back' => 'other_back',
            default => null,
        };
    }

    /**
     * The text narrowed to the range: from the first chapter it keeps to
     * the end of the last one's own sections, with the parts those sit in.
     * Front and back matter are left to the exclusions.
     */
    private static function narrow(Book $book, array $pages, array $range): array
    {
        $types = $book->sections()->pluck('type', 'id');
        $body = [];

        foreach ($pages as $i => $page) {
            if ($page['group'] === 'body' && $page['section_id'] !== null) {
                $body[] = $i;
            }
        }

        if (isset($range['first'])) {
            $chapters = array_values(array_filter($body, fn ($i) => $pages[$i]['state'] === 'ready' && ($types[$pages[$i]['section_id']] ?? null) === 'chapter'));
            $chapters = array_slice($chapters, 0, (int) $range['first']);
            [$from, $last] = $chapters === [] ? [null, null] : [$chapters[0], end($chapters)];
        } else {
            $from = collect($body)->first(fn ($i) => $pages[$i]['section_id'] === (int) $range['from']);
            $last = collect($body)->first(fn ($i) => $pages[$i]['section_id'] === (int) $range['to']);
        }

        if ($from === null || $last === null) {
            return array_values(array_filter($pages, fn ($p) => $p['group'] !== 'body'));
        }

        // The last kept section's own children come with it.
        $to = $last;

        foreach ($body as $i) {
            if ($i > $last && $pages[$i]['depth'] > $pages[$last]['depth']) {
                $to = $i;
            } elseif ($i > $last) {
                break;
            }
        }

        $kept = [];

        // The parts above the first kept section, so it still sits in one.
        $depth = $pages[$from]['depth'];

        for ($i = $from - 1; $i >= 0 && $depth > 0; $i--) {
            if ($pages[$i]['group'] === 'body' && $pages[$i]['section_id'] !== null && $pages[$i]['depth'] < $depth) {
                $kept[$i] = true;
                $depth = $pages[$i]['depth'];
            }
        }

        foreach ($body as $i) {
            if ($i >= $from && $i <= $to) {
                $kept[$i] = true;
            }
        }

        return array_values(array_filter($pages, fn ($p, $i) => $p['group'] !== 'body' || $p['section_id'] === null || isset($kept[$i]), ARRAY_FILTER_USE_BOTH));
    }

    /** Whether any section left in carries a footnote. */
    private static function hasNotes(Book $book, array $pages): bool
    {
        $ids = array_filter(array_column($pages, 'section_id'));

        return $ids !== [] && ManuscriptSection::whereIn('id', $ids)->get()
            ->contains(fn (ManuscriptSection $s) => str_contains((string) json_encode($s->asPublished()->content), '"type":"footnote"'));
    }

    /** A page the profile adds, shaped like Anatomy's generated pages. */
    private static function page(string $part, string $title, string $group, array $data): array
    {
        return [
            'part' => $part, 'source' => 'generated', 'group' => $group, 'section_id' => null, 'depth' => 0,
            'title' => $title, 'start' => $part === 'book_notes' ? 'recto' : 'any',
            'folio' => $group === 'front' ? 'roman' : 'arabic', 'restart_folio' => false,
            'show_folio' => $part === 'book_notes', 'running_head' => $part === 'book_notes', 'numbered' => false,
            'state' => 'ready', 'data' => $data,
        ];
    }

    /** @return array{continue: string, body: string, link: string, notes: string, review: string} */
    public static function words(string $language): array
    {
        return self::WORDS[strtolower(substr($language, 0, 2))] ?? self::WORDS['en'];
    }
}
