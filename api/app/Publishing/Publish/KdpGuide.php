<?php

namespace App\Publishing\Publish;

use App\Models\Book;
use App\Platform\House\House;
use App\Publishing\AiDisclosure;
use App\Publishing\Exporter;
use App\Publishing\IsbnPlan;
use App\Publishing\PrintPages;
use App\Publishing\RightsDeclaration;
use App\Publishing\Royalties;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Amazon KDP guided page: every value KDP's forms ask for, ready to
 * paste, in the order of its three pages (Details, Content, Pricing), for
 * the Kindle eBook and for the paperback.
 *
 * Amazon has no public publishing API (checked 2026-10-25), and nothing
 * here automates its website: no browser, no stored Amazon password. The
 * author opens KDP, pastes, uploads the files from the download links and
 * then tells Écrivain the ASIN.
 *
 * Limits are KDP's own (config publishing.publish.kdp, with the pages they
 * were read from): title and subtitle together under 200 characters, a
 * 4000-character description counting its HTML, seven keyword boxes (50
 * characters each — a figure KDP's help does not state; see the config),
 * three categories. KDP's form wording is paraphrased; where KDP's own
 * page is the authority the field links to it.
 *
 * The author name is the book's own: a book without one (a house series
 * published anonymously) gets no name here either, never the account's.
 */
class KdpGuide
{
    public const FORMATS = ['ebook', 'paperback'];

    /** The guided page for one format. @return array<string, mixed> */
    public function for(Book $book, string $format): array
    {
        $limits = config('publishing.publish.kdp');
        $sources = $limits['sources'];
        $plan = (($book->publish_plan ?? [])['kdp'] ?? []);
        $paperback = $format === 'paperback';

        $details = array_values(array_filter([
            $this->language($book, $limits),
            ...$this->titles($book, $limits),
            $this->series($book),
            $this->edition($book),
            $this->author($book),
            $this->contributors($book),
            $this->description($book, $limits),
            $this->rights($book),
            $this->audience($plan),
            ['key' => 'marketplace', 'label' => 'Primary marketplace', 'value' => 'Amazon.com', 'status' => 'ok', 'copy' => false,
                'note' => 'Prices below are US dollars for Amazon.com; KDP lists the other marketplaces on its Pricing page.'],
            $this->categories($book, $plan, $limits),
            $this->keywords($book, $plan, $limits),
        ]));

        $content = array_values(array_filter([
            $this->isbn($book, $format),
            $paperback ? $this->publicationDate($book) : null,
            $this->publisher($book),
            ...($paperback ? $this->printOptions($book) : []),
            ...$this->files($book, $format),
            $this->ai($book),
        ]));

        $pricing = $this->pricing($book, $format);

        $all = [...$details, ...$content, ...$pricing];
        $statuses = array_column($all, 'status');

        return [
            'format' => $format,
            'label' => $paperback ? 'Paperback' : 'Kindle eBook',
            'status' => in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok'),
            'url' => $limits['url'],
            'sections' => [
                ['key' => 'details', 'title' => ($paperback ? 'Paperback' : 'Kindle eBook').' Details', 'fields' => $details],
                ['key' => 'content', 'title' => ($paperback ? 'Paperback' : 'Kindle eBook').' Content', 'fields' => $content],
                ['key' => 'pricing', 'title' => ($paperback ? 'Paperback' : 'Kindle eBook').' Rights & Pricing', 'fields' => $pricing],
            ],
            'limits' => [
                'keywords' => $limits['keywords'],
                'keyword_chars' => $limits['keyword_chars'],
                'keyword_chars_verified' => $limits['keyword_chars_verified'],
                'categories' => $limits['categories'],
                'description' => $limits['description_max'],
            ],
            'sources' => $sources,
            'checked' => config('publishing.publish.checked'),
        ];
    }

    /**
     * The KDP checklist page: a guided page per chosen KDP format, per book,
     * in the order given (the shelf's and the House catalog's bulk action).
     *
     * @param  Collection<int, Book>  $books
     * @return array<string, mixed>
     */
    public function checklist(Collection $books, string $back): array
    {
        return [
            'books' => $books->map(fn (Book $book) => [
                'id' => $book->id,
                'title' => $book->title,
                'studio' => route('books.edit', ['book' => $book->id, 'pane' => 'publish']),
                'guides' => collect(self::FORMATS)
                    ->filter(fn ($f) => in_array($f === 'ebook' ? 'kdp_ebook' : 'kdp_paperback', PublishPlan::chosen($book), true))
                    ->map(fn ($f) => $this->for($book, $f))->values()->all(),
            ])->values()->all(),
            'bulk' => $books->count() > 1,
            'back' => $back,
            'kdp_url' => config('publishing.publish.kdp.url'),
        ];
    }

    /**
     * The description as KDP takes it: the book's plain-text description,
     * escaped, with paragraphs as <p> and single line breaks as <br>.
     */
    public static function descriptionHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($text)));

        if ($text === '') {
            return '';
        }

        return collect(preg_split("/\n{2,}/", $text))
            ->map(fn ($paragraph) => '<p>'.implode('<br>', array_map(
                fn ($line) => htmlspecialchars(trim($line), ENT_QUOTES | ENT_HTML5, 'UTF-8', false),
                explode("\n", trim($paragraph)),
            )).'</p>')
            ->implode('');
    }

    /**
     * Seven keyword suggestions: the author's own keywords first, then the
     * category's parts and the series, each at most the box's length, none
     * repeating the title (KDP: words in the title are already searched).
     *
     * @return list<string>
     */
    public static function suggestKeywords(Book $book): array
    {
        $limits = config('publishing.publish.kdp');
        $meta = $book->meta ?? [];
        $title = Str::lower((string) $book->title);

        $candidates = [
            ...explode(',', (string) ($meta['keywords'] ?? '')),
            ...array_reverse(explode('/', (string) ($meta['category'] ?? ''))),
            (string) $book->series_name,
            (string) $book->genre,
        ];

        return collect($candidates)
            ->map(fn ($k) => trim(preg_replace('/\s+/', ' ', (string) $k)))
            ->filter(fn ($k) => $k !== '' && Str::lower($k) !== $title)
            ->map(fn ($k) => self::fit($k, $limits['keyword_chars']))
            ->unique(fn ($k) => Str::lower($k))
            ->take($limits['keywords'])
            ->values()
            ->all();
    }

    /** KDP's language name for the book, or null where KDP has none. */
    public static function language(Book $book, ?array $limits = null): ?array
    {
        $limits ??= config('publishing.publish.kdp');
        $code = substr(Exporter::languageCode($book->language), 0, 2);
        $name = $limits['languages'][$code] ?? null;

        return [
            'key' => 'language',
            'label' => 'Language',
            'value' => $name,
            'status' => $name ? 'ok' : 'fail',
            'copy' => false,
            'note' => $name ? 'Choose it in KDP\'s list.' : 'KDP does not list '.($book->language ?: 'this language').' among the languages it publishes.',
            'source' => $limits['sources']['languages'],
        ];
    }

    // ── Details ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function titles(Book $book, array $limits): array
    {
        $title = trim((string) $book->title);
        $subtitle = trim((string) $book->subtitle);
        $length = mb_strlen($title) + mb_strlen($subtitle);
        $over = $length > $limits['title_subtitle_max'];

        return [
            ['key' => 'title', 'label' => 'Book title', 'value' => $title, 'copy' => true,
                'status' => $title === '' ? 'fail' : ($over ? 'fail' : 'ok'),
                'note' => $over ? "Title and subtitle together are {$length} characters; KDP takes fewer than 200." : null,
                'fix' => $title === '' || $over ? ['pane' => 'settings', 'label' => 'Edit in Book Settings'] : null,
                'source' => $limits['sources']['title']],
            ['key' => 'subtitle', 'label' => 'Subtitle', 'value' => $subtitle ?: null, 'copy' => $subtitle !== '',
                'status' => $over ? 'fail' : 'ok', 'note' => $subtitle === '' ? 'Optional. Leave blank.' : null],
        ];
    }

    private function series(Book $book): array
    {
        $name = trim((string) $book->series_name);

        return ['key' => 'series', 'label' => 'Series', 'copy' => $name !== '', 'status' => 'ok',
            'value' => $name !== '' ? $name : null,
            'extra' => $name !== '' && $book->series_number ? ['label' => 'Series number', 'value' => (string) $book->series_number] : null,
            'note' => $name === '' ? 'Not part of a series: skip this.' : 'In KDP, add the book to the series by its name, then give its number.'];
    }

    private function edition(Book $book): array
    {
        $statement = (string) ($book->publication()['edition_statement'] ?? '');
        $number = preg_match('/(\d+)/', $statement, $m) ? $m[1] : null;

        return ['key' => 'edition', 'label' => 'Edition number', 'value' => $number, 'copy' => $number !== null, 'status' => 'ok',
            'note' => $number ? null : 'Optional. Leave blank for a first edition.'];
    }

    private function author(Book $book): array
    {
        $name = trim((string) $book->author_name);

        if ($name !== '') {
            return ['key' => 'author', 'label' => 'Author (primary author or contributor)', 'value' => $name, 'copy' => true, 'status' => 'ok',
                'note' => 'Exactly as on the cover.'];
        }

        return House::isHouseBook($book)
            ? ['key' => 'author', 'label' => 'Author (primary author or contributor)', 'value' => null, 'copy' => false, 'status' => 'warn',
                'note' => 'This imprint publishes the book without an author name, so Écrivain gives none: no personal name, pen name or "Anonymous". KDP\'s form asks for a primary author; how the imprint fills it is the publisher\'s decision.']
            : ['key' => 'author', 'label' => 'Author (primary author or contributor)', 'value' => null, 'copy' => false, 'status' => 'fail',
                'note' => 'The book has no author name.', 'fix' => ['pane' => 'export', 'label' => 'Add it on Export & Publish']];
    }

    private function contributors(Book $book): ?array
    {
        $credits = collect($book->publication()['credits'] ?? [])
            ->filter(fn ($c) => trim((string) ($c['name'] ?? '')) !== '')
            ->map(fn ($c) => trim((string) ($c['role'] ?? 'Contributor')).': '.trim((string) $c['name']))
            ->values()->all();

        return ['key' => 'contributors', 'label' => 'Contributors', 'value' => $credits === [] ? null : $credits, 'copy' => $credits !== [], 'status' => 'ok',
            'note' => $credits === [] ? 'None. Optional.' : 'Add each one with their role.'];
    }

    private function description(Book $book, array $limits): array
    {
        $html = self::descriptionHtml((string) (($book->meta ?? [])['description'] ?? ''));
        $length = mb_strlen($html);

        return [
            'key' => 'description', 'label' => 'Description', 'value' => $html ?: null, 'copy' => $html !== '',
            'html' => true,
            'status' => $html === '' ? 'fail' : ($length > $limits['description_max'] ? 'fail' : 'ok'),
            'note' => $html === ''
                ? 'No description yet.'
                : ($length > $limits['description_max']
                    ? "{$length} characters with its HTML; KDP takes {$limits['description_max']}."
                    : "{$length} of {$limits['description_max']} characters, HTML included. Paste it into KDP's description box."),
            'fix' => $html === '' || $length > $limits['description_max'] ? ['pane' => 'export', 'label' => 'Edit the description'] : null,
            'source' => $limits['sources']['description'],
        ];
    }

    private function rights(Book $book): array
    {
        return RightsDeclaration::confirmed($book)
            ? ['key' => 'publishing_rights', 'label' => 'Publishing rights', 'value' => 'I own the copyright and I hold the necessary publishing rights.', 'copy' => false, 'status' => 'ok',
                'note' => 'From the rights declaration confirmed by '.(RightsDeclaration::for($book)['confirmed_by'] ?? 'the author').'. Choose the public-domain option only for a public-domain work.']
            : ['key' => 'publishing_rights', 'label' => 'Publishing rights', 'value' => null, 'copy' => false, 'status' => 'fail',
                'note' => 'Confirm the rights declaration first.', 'fix' => ['pane' => 'rights', 'label' => 'ISBN & Rights']];
    }

    private function audience(array $plan): array
    {
        $explicit = $plan['explicit'] ?? null;
        $min = $plan['min_age'] ?? null;
        $max = $plan['max_age'] ?? null;

        return [
            'key' => 'audience', 'label' => 'Primary audience', 'copy' => false, 'editable' => 'audience',
            'value' => $explicit === null ? null : ($explicit ? 'Contains sexually explicit images or title: Yes' : 'Sexually explicit images or title: No'),
            'extra' => $min || $max ? ['label' => 'Reading age', 'value' => trim(($min ?: '?').'–'.($max ?: '?'))] : null,
            'audience' => ['explicit' => $explicit, 'min_age' => $min, 'max_age' => $max],
            'status' => $explicit === null ? 'warn' : 'ok',
            'note' => $explicit === null ? 'Say whether the book has sexually explicit images or title. The reading age is optional.' : null,
        ];
    }

    private function categories(Book $book, array $plan, array $limits): array
    {
        $saved = array_values(array_filter((array) ($plan['categories'] ?? [])));
        $suggested = array_values(array_filter([trim((string) (($book->meta ?? [])['category'] ?? ''))]));
        $value = $saved ?: $suggested;

        return [
            'key' => 'categories', 'label' => "Categories (up to {$limits['categories']})", 'value' => $value ?: null, 'copy' => $value !== [],
            'editable' => 'categories', 'saved' => $saved !== [],
            'status' => $value === [] ? 'warn' : 'ok',
            'note' => ($saved ? '' : 'Suggested from the book\'s category. ').'KDP\'s category names are its own: choose the closest in its list.',
            'source' => $limits['sources']['categories'],
        ];
    }

    private function keywords(Book $book, array $plan, array $limits): array
    {
        $saved = array_values(array_filter(array_map('trim', (array) ($plan['keywords'] ?? []))));
        $value = $saved ?: self::suggestKeywords($book);
        $tooLong = array_values(array_filter($value, fn ($k) => mb_strlen($k) > $limits['keyword_chars']));

        return [
            'key' => 'keywords', 'label' => "Keywords ({$limits['keywords']} boxes)", 'value' => $value ?: null, 'copy' => $value !== [],
            'editable' => 'keywords', 'saved' => $saved !== [],
            'status' => $tooLong ? 'fail' : ($value === [] ? 'warn' : 'ok'),
            'note' => ($saved ? '' : 'Suggested from your keywords, category and series: edit and save them. ')
                ."One per box, at most {$limits['keyword_chars']} characters each".($limits['keyword_chars_verified'] ? '' : ' (KDP\'s help does not state the box length; check its form)').'.',
            'source' => $limits['sources']['keywords'],
        ];
    }

    // ── Content ─────────────────────────────────────────────────────────

    private function isbn(Book $book, string $format): array
    {
        $plan = IsbnPlan::plan($book);
        $edition = $format === 'paperback' ? $plan['paperback'] : $plan['ebook'];

        if ($edition['isbn']) {
            return ['key' => 'isbn', 'label' => $format === 'paperback' ? 'Print ISBN' : 'ISBN', 'value' => $edition['isbn'], 'copy' => true, 'status' => 'ok',
                'note' => $format === 'paperback' ? 'Choose "Use my own ISBN" and paste it with the imprint below.' : 'Optional at Kindle; paste it to keep your own number on the listing.'];
        }

        if ($edition['source'] === 'own') {
            return ['key' => 'isbn', 'label' => $format === 'paperback' ? 'Print ISBN' : 'ISBN', 'value' => null, 'copy' => false, 'status' => 'fail',
                'note' => 'The ISBN plan says your own ISBN, but none is assigned.', 'fix' => ['pane' => 'rights', 'label' => 'ISBN & Rights']];
        }

        return $format === 'paperback'
            ? ['key' => 'isbn', 'label' => 'Print ISBN', 'value' => 'Get a free KDP ISBN', 'copy' => false, 'status' => 'ok',
                'note' => 'KDP\'s free ISBN "can only be used on KDP", and the imprint then shows "Independently published".']
            : ['key' => 'isbn', 'label' => 'ISBN', 'value' => null, 'copy' => false, 'status' => 'ok', 'note' => 'Optional for Kindle eBooks: leave it blank.'];
    }

    private function publicationDate(Book $book): array
    {
        $date = $book->launch_date?->toDateString();

        return ['key' => 'publication_date', 'label' => 'Publication date', 'value' => $date, 'copy' => $date !== null, 'status' => 'ok',
            'note' => $date ? null : 'Optional: leave blank for today.'];
    }

    private function publisher(Book $book): array
    {
        $publication = $book->publication();
        $name = trim((string) ($publication['imprint'] ?: $publication['publisher'] ?: (House::isHouseBook($book) ? $book->user?->name : '')));

        return ['key' => 'publisher', 'label' => 'Publisher (imprint)', 'value' => $name ?: null, 'copy' => $name !== '', 'status' => 'ok',
            'note' => $name ? 'Use it with your own ISBN.' : 'Optional. Set the publisher in Book Settings → Publishing details.'];
    }

    /** @return list<array<string, mixed>> */
    private function printOptions(Book $book): array
    {
        $print = $book->printSettings();
        $trim = $book->layout()['trim'];

        $ink = match ($print['ink']) {
            'standard_color' => 'Standard color interior with white paper',
            'premium_color' => 'Premium color interior with white paper',
            default => 'Black & white interior with '.$print['paper'].' paper',
        };

        return [
            ['key' => 'ink', 'label' => 'Ink and paper type', 'value' => $ink, 'copy' => false, 'status' => 'ok', 'fix' => ['pane' => 'export', 'label' => 'Print & price']],
            ['key' => 'trim', 'label' => 'Trim size', 'value' => $trim, 'copy' => false, 'status' => 'ok', 'fix' => ['pane' => 'layout', 'label' => 'Design & Layout']],
            ['key' => 'bleed', 'label' => 'Bleed settings', 'value' => 'No Bleed', 'copy' => false, 'status' => 'ok',
                'note' => 'The interior keeps every picture inside the margins.'],
            ['key' => 'finish', 'label' => 'Paperback cover finish', 'value' => ucfirst($print['finish']), 'copy' => false, 'status' => 'ok'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function files(Book $book, string $format): array
    {
        $want = $format === 'paperback'
            ? [['pdf_interior', null, 'Manuscript (print interior PDF)'], ['cover_wrap', 'kdp', 'Book cover (KDP cover wrap PDF)']]
            : [['epub', null, 'Manuscript (EPUB)']];

        $fields = [];

        foreach ($want as [$fileFormat, $service, $label]) {
            $file = Freshness::file($book, $fileFormat, $service);
            $fields[] = [
                'key' => 'file_'.$fileFormat, 'label' => $label, 'copy' => false,
                'value' => $file['export'] ? $file['export']->label().' · '.$file['export']->created_at->toDateString() : null,
                'download' => $file['export'] ? route('publishing.download', [$book->id, $file['export']->id]) : null,
                'status' => $file['fresh'] ? 'ok' : ($file['export'] ? 'warn' : 'fail'),
                'note' => $file['fresh'] ? 'Download it, then upload it on KDP.' : $file['reason'].' Regenerate the files.',
                'regenerate' => ! $file['fresh'],
            ];
        }

        if ($format === 'ebook') {
            $fields[] = ['key' => 'file_cover', 'label' => 'Kindle eBook cover (JPG)', 'copy' => false, 'value' => 'Front cover',
                'download' => route('design.cover.download', $book->id), 'status' => 'ok',
                'note' => 'Upload it as "a cover you already have".', 'fix' => ['pane' => 'cover', 'label' => 'Cover Studio']];
        }

        return $fields;
    }

    private function ai(Book $book): array
    {
        $source = config('publishing.publish.kdp.sources.ai');

        if (! AiDisclosure::confirmed($book)) {
            return ['key' => 'ai', 'label' => 'AI-generated content', 'value' => null, 'copy' => false, 'status' => 'fail',
                'note' => 'Answer the AI-content questions first: KDP asks them.', 'fix' => ['pane' => 'rights', 'label' => 'ISBN & Rights'], 'source' => $source];
        }

        $kdp = AiDisclosure::for($book)['kdp'];
        $names = ['text' => 'Text', 'images' => 'Images', 'translations' => 'Translations'];
        $lines = collect($kdp)->map(fn ($generated, $category) => ($names[$category] ?? $category).': '.($generated ? 'Yes, AI-generated' : 'No'))->values()->all();
        $outdated = AiDisclosure::for($book)['outdated'];

        return ['key' => 'ai', 'label' => 'AI-generated content', 'value' => $lines, 'copy' => false,
            'status' => $outdated ? 'warn' : 'ok',
            'note' => $outdated
                ? 'The book\'s records show more AI use ('.implode(', ', $outdated).') than these answers. Review them in ISBN & Rights.'
                : 'Your confirmed answers. KDP asks only about AI-generated content, not AI-assisted editing.',
            'source' => $source];
    }

    // ── Pricing ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function pricing(Book $book, string $format): array
    {
        $prices = Royalties::prices($book);
        $fields = [[
            'key' => 'territories', 'label' => 'Territories', 'value' => 'All territories (worldwide rights)', 'copy' => false,
            'status' => RightsDeclaration::confirmed($book) ? 'ok' : 'warn',
            'note' => 'Choose individual territories only if your rights are limited to some countries.',
        ]];

        if ($format === 'paperback') {
            $pages = PrintPages::for($book);
            $terms = Royalties::kdpPaperback($prices['print'], $pages['pages'], $book->printSettings()['ink'], $book->layout()['trim']);
            $low = $prices['print'] !== null && $terms['minimum_price'] !== null && $prices['print'] < $terms['minimum_price'];

            $fields[] = [
                'key' => 'price', 'label' => 'List price, Amazon.com (USD)', 'copy' => $prices['print'] !== null,
                'value' => $prices['print'] !== null ? number_format($prices['print'], 2, '.', '') : null,
                'status' => $prices['print'] === null || $low ? 'fail' : 'ok',
                'royalty' => $terms['royalty'],
                'note' => $prices['print'] === null
                    ? 'Set the paperback price.'
                    : ($low ? sprintf('Below KDP\'s lowest price for this book ($%s), which covers printing.', number_format($terms['minimum_price'], 2))
                        : sprintf('Royalty about $%s a copy: %s', number_format((float) $terms['royalty'], 2), $terms['note'])),
                'fix' => $prices['print'] === null || $low ? ['pane' => 'export', 'label' => 'Print & price'] : null,
                'source' => $terms['source'],
            ];
            $fields[] = ['key' => 'expanded', 'label' => 'Expanded Distribution', 'value' => 'Your choice', 'copy' => false, 'status' => 'ok',
                'note' => $terms['expanded_royalty'] !== null
                    ? sprintf('Through Expanded Distribution the royalty would be about $%s a copy.', number_format((float) $terms['expanded_royalty'], 2))
                    : null];

            return $fields;
        }

        // With the EPUB's size, so the delivery cost is in the royalty.
        $kindle = Royalties::forBook($book)['ebook'][0];
        $fields[] = ['key' => 'select', 'label' => 'KDP Select enrollment', 'value' => 'Your choice', 'copy' => false, 'status' => 'ok',
            'note' => 'KDP Select asks for ebook exclusivity to Amazon: leave it off if the ebook is also sold in your store or on Google Play.'];
        $fields[] = ['key' => 'royalty_plan', 'label' => 'Royalty plan', 'value' => $kindle['rate'] !== null ? round($kindle['rate'] * 100).'%' : null, 'copy' => false,
            'status' => $kindle['problem'] ? 'warn' : 'ok', 'note' => $kindle['problem'] ?? $kindle['note'], 'source' => $kindle['source']];
        $fields[] = [
            'key' => 'price', 'label' => 'List price, Amazon.com (USD)', 'copy' => $prices['ebook'] !== null,
            'value' => $prices['ebook'] !== null ? number_format($prices['ebook'], 2, '.', '') : null,
            'royalty' => $kindle['royalty'],
            'status' => $prices['ebook'] === null ? 'fail' : 'ok',
            'note' => $prices['ebook'] === null ? 'Set the list price.' : sprintf('Royalty about $%s a copy.', number_format((float) $kindle['royalty'], 2)),
            'fix' => $prices['ebook'] === null ? ['pane' => 'export', 'label' => 'Set the price'] : null,
        ];

        return $fields;
    }

    private static function fit(string $keyword, int $max): string
    {
        if (mb_strlen($keyword) <= $max) {
            return $keyword;
        }

        $cut = mb_substr($keyword, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 0 ? mb_substr($cut, 0, $space) : mb_substr($keyword, 0, $max));
    }
}
