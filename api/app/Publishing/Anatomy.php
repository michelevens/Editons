<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\Isbn;
use App\Models\ManuscriptSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A book's anatomy: which conventional parts it has, in what order they
 * print, which pages the app must synthesize, and how each page is treated.
 *
 * The single place the renderers (PrintBook, Exporter, DocumentRenderer)
 * learn the book's structure from — see docs/INTEGRATION-book-parts.md. It
 * reads; it never draws a page. The only writes are the two the author asks
 * for from the Book parts checklist: reorder() and add().
 *
 * Generated pages are built from the book's own data only. A field the
 * author left empty prints nothing: no default publisher, no current year
 * standing in for a copyright year, no ISBN that has not passed its
 * checksum.
 */
class Anatomy
{
    /** Book types grouped by which conventional parts they usually carry. */
    public const PROFILES = [
        'fiction' => ['fiction', 'graphic_novel', 'childrens', 'poetry', 'anthology'],
        'educational' => ['educational', 'technical'],
        'academic' => ['academic', 'masters_thesis', 'phd_dissertation', 'phd_publication', 'masters_project'],
    ];

    /**
     * Recommended parts per profile. Each entry is a set of alternatives:
     * [Foreword, Preface] is satisfied by either.
     *
     * @var array<string, list<list<string>>>
     */
    private const RECOMMENDED = [
        'fiction' => [
            ['half_title'], ['title_page'], ['copyright_page'], ['dedication'],
            ['table_of_contents'], ['acknowledgments'], ['about_author'],
        ],
        'nonfiction' => [
            ['half_title'], ['title_page'], ['copyright_page'], ['table_of_contents'],
            ['preface', 'introduction'], ['acknowledgments'], ['notes', 'bibliography'],
            ['index'], ['about_author'],
        ],
        // Exam preparation and textbooks: who it is for and how to use it, a
        // glossary to revise from, an index to find things in.
        'educational' => [
            ['half_title'], ['title_page'], ['copyright_page'], ['table_of_contents'],
            ['foreword', 'preface'], ['introduction'], ['glossary'], ['index'],
        ],
        'academic' => [
            ['half_title'], ['title_page'], ['copyright_page'], ['table_of_contents'],
            ['acknowledgments'], ['abbreviations'], ['introduction'], ['conclusion'],
            ['bibliography'], ['appendix'], ['index'],
        ],
    ];

    /** Parts a book may have more than one of. */
    private const REPEATABLE = ['appendix', 'chapter', 'part'];

    /** Copyright-page and generated-page wording, by language. */
    private const WORDS = [
        'en' => [
            'notice' => 'Copyright © :year :holder', 'notice_no_year' => 'Copyright © :holder',
            'rights' => 'All rights reserved.', 'publisher' => 'Published by :name',
            'series' => 'Series: :name', 'series_number' => 'Series: :name, no. :number',
            'isbn' => ['print' => 'print', 'epub' => 'EPUB', 'pdf' => 'PDF'],
            'credit' => ':role: :name', 'also_by' => 'Also by :author', 'also_by_anonymous' => 'By the Same Author',
        ],
        'fr' => [
            'notice' => '© :holder, :year', 'notice_no_year' => '© :holder',
            'rights' => 'Tous droits de reproduction, de traduction et d’adaptation réservés pour tous pays.',
            'publisher' => 'Éditeur : :name',
            'series' => 'Collection « :name »', 'series_number' => 'Collection « :name », n° :number',
            'isbn' => ['print' => 'version imprimée', 'epub' => 'EPUB', 'pdf' => 'PDF'],
            'credit' => ':role : :name', 'also_by' => 'Du même auteur', 'also_by_anonymous' => 'Du même auteur',
        ],
        'ht' => [
            'notice' => '© :year :holder', 'notice_no_year' => '© :holder',
            'rights' => 'Tout dwa rezève.', 'publisher' => 'Pibliye pa :name',
            'series' => 'Koleksyon « :name »', 'series_number' => 'Koleksyon « :name », nº :number',
            'isbn' => ['print' => 'vèsyon enprime', 'epub' => 'EPUB', 'pdf' => 'PDF'],
            'credit' => ':role : :name', 'also_by' => 'Lòt liv menm otè a', 'also_by_anonymous' => 'Lòt liv menm otè a',
        ],
        'es' => [
            'notice' => '© :year, :holder', 'notice_no_year' => '© :holder',
            'rights' => 'Reservados todos los derechos.', 'publisher' => 'Publicado por :name',
            'series' => 'Colección «:name»', 'series_number' => 'Colección «:name», n.º :number',
            'isbn' => ['print' => 'impreso', 'epub' => 'EPUB', 'pdf' => 'PDF'],
            'credit' => ':role: :name', 'also_by' => 'Del mismo autor', 'also_by_anonymous' => 'Del mismo autor',
        ],
        'de' => [
            'notice' => '© :year :holder', 'notice_no_year' => '© :holder',
            'rights' => 'Alle Rechte vorbehalten.', 'publisher' => 'Verlag: :name',
            'series' => 'Reihe „:name“', 'series_number' => 'Reihe „:name“, Band :number',
            'isbn' => ['print' => 'Druckausgabe', 'epub' => 'EPUB', 'pdf' => 'PDF'],
            'credit' => ':role: :name', 'also_by' => 'Vom selben Autor', 'also_by_anonymous' => 'Vom selben Autor',
        ],
        'pt' => [
            'notice' => '© :year :holder', 'notice_no_year' => '© :holder',
            'rights' => 'Todos os direitos reservados.', 'publisher' => 'Publicado por :name',
            'series' => 'Coleção «:name»', 'series_number' => 'Coleção «:name», n.º :number',
            'isbn' => ['print' => 'impresso', 'epub' => 'EPUB', 'pdf' => 'PDF'],
            'credit' => ':role: :name', 'also_by' => 'Do mesmo autor', 'also_by_anonymous' => 'Do mesmo autor',
        ],
    ];

    /** Pages the app synthesizes in the front matter, in Chicago order. */
    private const FRONT_GENERATED = ['half_title', 'series_page', 'title_page', 'copyright_page', 'table_of_contents'];

    /** Where front matter nobody has named sorts: after the copyright page. */
    private const UNNAMED_FRONT_ORDER = 55;

    private const UNNAMED_BACK_ORDER = 305;

    /** The book's language as a short code: en, fr, es, ht, de, pt. */
    public static function language(Book $book): string
    {
        return BookPart::normaliseLanguage($book->language);
    }

    /** fiction | nonfiction | educational | academic */
    public static function profile(Book $book): string
    {
        foreach (self::PROFILES as $profile => $types) {
            if (in_array($book->book_type, $types, true)) {
                return $profile;
            }
        }

        if ($book->studio === 'academic') {
            return 'academic';
        }

        return match (mb_strtolower((string) $book->genre)) {
            'fiction', 'novel', 'roman' => 'fiction',
            'textbook', 'technical', 'educational', 'exam prep' => 'educational',
            default => 'nonfiction',
        };
    }

    /**
     * Recommended parts for the book, as sets of alternatives.
     *
     * @return list<list<BookPart>>
     */
    public static function recommendations(Book $book): array
    {
        $sets = self::RECOMMENDED[self::profile($book)];

        if ($book->series_name) {
            $sets[] = ['series_page'];

            if (self::profile($book) === 'educational') {
                $sets[] = ['about_series'];
            }
        }

        return array_values(array_filter(array_map(
            fn (array $set) => array_map(fn (string $key) => BookPart::from($key), $set),
            $sets,
        ), function (array $set) use ($book) {
            // An anonymous book has nobody to write about.
            return ! ($set === [BookPart::AboutAuthor] && ! $book->author_name);
        }));
    }

    /**
     * ISBNs by format, as they will print. Only numbers that pass the
     * ISBN-13 checksum: an invalid one is someone else's book.
     *
     * Falls back to the account's assigned ISBNs (Rights screen) and the
     * legacy books.isbn for print, so a book set up before this metadata
     * existed still prints its number.
     *
     * @return array{print: ?string, epub: ?string, pdf: ?string}
     */
    public static function isbns(Book $book): array
    {
        $publication = $book->publication();
        $assigned = $book->exists
            ? $book->isbns()->get()->keyBy('edition_format')
            : collect();

        $first = function (array $candidates): ?string {
            foreach ($candidates as $candidate) {
                if ($candidate && Isbn::isValid((string) $candidate)) {
                    return trim((string) $candidate);
                }
            }

            return null;
        };

        return [
            'print' => $first([
                $publication['isbn_print'],
                $assigned->get('paperback')?->isbn,
                $assigned->get('hardcover')?->isbn,
                $book->isbn,
            ]),
            'epub' => $first([$publication['isbn_epub'], $assigned->get('ebook')?->isbn]),
            'pdf' => $first([$publication['isbn_pdf']]),
        ];
    }

    /** Who the copyright belongs to: the stated holder, the author, else the publisher. */
    public static function copyrightHolder(Book $book): ?string
    {
        $publication = $book->publication();

        foreach ([$publication['copyright_holder'], $book->author_name, $publication['publisher']] as $candidate) {
            if (trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    /**
     * The copyright page, line by line, from the book's metadata.
     *
     * Chicago 1.19–1.38: publisher, copyright notice, rights, edition,
     * series, ISBNs, legal deposit (the cataloging-data slot), credits,
     * disclaimer, printing line. Each key appears only when its data
     * exists.
     *
     * @return list<array{key: string, text: string}>
     */
    public static function copyrightLines(Book $book, ?string $language = null): array
    {
        $language = $language ? BookPart::normaliseLanguage($language) : self::language($book);
        $words = self::WORDS[$language] ?? self::WORDS['en'];
        $p = $book->publication();
        $clean = fn ($value) => trim((string) $value);
        $lines = [];

        $add = function (string $key, string $text) use (&$lines) {
            if (trim($text) !== '') {
                $lines[] = ['key' => $key, 'text' => trim($text)];
            }
        };

        $names = array_values(array_unique(array_filter([$clean($p['imprint']), $clean($p['publisher'])])));

        if ($names) {
            $name = implode(', ', $names).($clean($p['place']) !== '' ? ', '.$clean($p['place']) : '');
            $add('publisher', self::fill($words['publisher'], ['name' => $name]));
        }

        $holder = self::copyrightHolder($book);

        if ($holder) {
            $year = $p['copyright_year'] ? (string) (int) $p['copyright_year'] : null;
            $add('notice', self::fill($year ? $words['notice'] : $words['notice_no_year'], ['holder' => $holder, 'year' => $year]));
        }

        if ($clean($p['rights_statement']) !== '') {
            $add('rights', $clean($p['rights_statement']));
        } elseif ($holder) {
            $add('rights', $words['rights']);
        }

        $add('edition', $clean($p['edition_statement']));

        if ($clean($book->series_name) !== '') {
            $add('series', self::fill(
                $book->series_number ? $words['series_number'] : $words['series'],
                ['name' => $clean($book->series_name), 'number' => (string) $book->series_number],
            ));
        }

        foreach (self::isbns($book) as $format => $isbn) {
            if ($isbn) {
                $add('isbn_'.$format, 'ISBN '.$isbn.' ('.$words['isbn'][$format].')');
            }
        }

        $add('legal_deposit', $clean($p['legal_deposit']));

        foreach ($p['credits'] ?? [] as $credit) {
            $role = $clean($credit['role'] ?? '');
            $name = $clean($credit['name'] ?? '');

            if ($role !== '' && $name !== '') {
                $add('credit', self::fill($words['credit'], ['role' => $role, 'name' => $name]));
            }
        }

        $add('disclaimer', $clean($p['disclaimer']));
        $add('printing', $clean($p['printing']));

        return $lines;
    }

    /**
     * The other books in this book's series, by the same owner, in series
     * order. Archived books and this one are left out.
     *
     * @return Collection<int, Book>
     */
    public static function seriesBooks(Book $book): Collection
    {
        if (trim((string) $book->series_name) === '' || ! $book->user_id) {
            return collect();
        }

        return Book::where('user_id', $book->user_id)
            ->where('series_name', $book->series_name)
            ->whereKeyNot($book->id)
            ->where('status', '!=', 'archived')
            ->orderByRaw('series_number IS NULL, series_number')
            ->orderBy('title')
            ->get(['id', 'title', 'subtitle', 'series_number', 'status']);
    }

    /**
     * The owner's other PUBLISHED books under the same author name, for an
     * "Also by" page when the book is in no series.
     *
     * @return Collection<int, Book>
     */
    public static function alsoBy(Book $book): Collection
    {
        if (trim((string) $book->author_name) === '' || ! $book->user_id) {
            return collect();
        }

        return Book::where('user_id', $book->user_id)
            ->where('author_name', $book->author_name)
            ->whereKeyNot($book->id)
            ->where('status', 'published')
            ->orderBy('title')
            ->get(['id', 'title', 'subtitle']);
    }

    /**
     * The book, in print order, as the renderers consume it.
     *
     * Each page entry:
     *   part          BookPart key, or null for a section nobody has named
     *   group         front | body | back
     *   source        generated (the app draws it) | author (a section)
     *   section_id    the manuscript section, or null for a synthesized page
     *   depth         0 for top-level, 1 for a chapter inside a part, …
     *   title         heading, in the book's language for generated pages
     *   start         recto | verso | any
     *   folio         roman | arabic
     *   restart_folio true on the first arabic page: number it 1
     *   show_folio    false on display pages (blind folio)
     *   running_head  false on display pages
     *   numbered      true when the part takes a chapter number
     *   state         ready | empty | placeholder (template guidance left in)
     *   data          what a generated page prints (see each builder)
     *
     * Order: the author's binder order, with the generated pages merged in
     * at their conventional places (half title, series page, title page,
     * copyright page ahead of the front matter; contents after dedication
     * and epigraph). The binder is respected rather than silently re-sorted;
     * reorder() is how the author asks for the conventional order.
     *
     * @return array{language: string, profile: string, words: array<string, string>, cover: array<string, bool>, pages: list<array<string, mixed>>}
     */
    public static function plan(Book $book): array
    {
        $language = self::language($book);
        $sections = $book->sections()->orderBy('position')->orderBy('id')->get();
        $children = $sections->groupBy(fn (ManuscriptSection $s) => (int) $s->parent_id);
        $roots = $children->get(0, collect())->values();

        $present = $sections->map(fn (ManuscriptSection $s) => $s->part)->filter()->unique()->all();
        $pending = [];

        foreach (self::FRONT_GENERATED as $key) {
            $part = BookPart::from($key);

            // A hand-written copyright page or a placed Contents section
            // takes the generated page's place.
            if (in_array($key, $present, true)) {
                continue;
            }

            $page = self::generatedPage($book, $part, $language);

            if ($page) {
                $pending[] = $page;
            }
        }

        $pages = [];
        $flush = function (?int $before) use (&$pending, &$pages) {
            while ($pending && ($before === null || BookPart::from($pending[0]['part'])->order() < $before)) {
                $pages[] = array_shift($pending);
            }
        };

        $walk = function (ManuscriptSection $section, int $depth) use (&$walk, &$pages, $children, $book, $language) {
            $pages[] = self::sectionPage($book, $section, $depth, $language);

            foreach ($children->get($section->id, collect()) as $child) {
                $walk($child, $depth + 1);
            }
        };

        foreach ($roots as $section) {
            [$group, $order] = self::placement($section);

            $flush($group === 'front' ? $order : null);
            $walk($section, 0);
        }

        $flush(null);

        return [
            'language' => $language,
            'profile' => self::profile($book),
            'words' => [
                'contents' => BookPart::TableOfContents->title($language),
                'part' => BookPart::Part->title($language),
                'chapter' => BookPart::Chapter->title($language),
            ],
            // The cover is the first page of the reading PDF and the EPUB's
            // cover image; it is never part of the print-ready interior,
            // which the printer binds inside a separately supplied cover.
            'cover' => ['reading_pdf' => true, 'epub' => true, 'print_interior' => false],
            'pages' => self::paginate($pages),
        ];
    }

    /** Folio style and restart, once the order is known. */
    private static function paginate(array $pages): array
    {
        $arabic = false;

        foreach ($pages as $i => $page) {
            $starts = ! $arabic && $page['folio'] === 'arabic';
            $arabic = $arabic || $starts;

            $pages[$i]['folio'] = $arabic ? 'arabic' : 'roman';
            $pages[$i]['restart_folio'] = $starts;

            // The text always opens on a recto (Chicago 1.5).
            if ($starts) {
                $pages[$i]['start'] = 'recto';
            }
        }

        return array_values($pages);
    }

    /** @return array<string, mixed>|null */
    private static function generatedPage(Book $book, BookPart $part, string $language): ?array
    {
        $words = self::WORDS[$language] ?? self::WORDS['en'];
        $p = $book->publication();

        $data = match ($part) {
            BookPart::HalfTitle => ['title' => $book->title],
            BookPart::TitlePage => [
                'title' => $book->title,
                'subtitle' => $book->subtitle,
                // Null for an anonymous book: nothing is put in its place.
                'author' => $book->author_name ?: null,
                'publisher' => $p['publisher'],
                'imprint' => $p['imprint'],
                'place' => $p['place'],
                'series' => $book->series_name ? ['name' => $book->series_name, 'number' => $book->series_number] : null,
            ],
            BookPart::CopyrightPage => ['lines' => self::copyrightLines($book, $language)],
            BookPart::TableOfContents => ['heading' => $part->title($language)],
            BookPart::SeriesPage => self::seriesPageData($book, $words, $language),
            default => null,
        };

        if ($data === null) {
            return null;
        }

        return self::page($part, [
            'source' => 'generated',
            'title' => $part === BookPart::SeriesPage ? $data['heading'] : $part->title($language),
            'data' => $data,
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function seriesPageData(Book $book, array $words, string $language): ?array
    {
        $series = self::seriesBooks($book);

        if ($series->isNotEmpty()) {
            return [
                'kind' => 'series',
                'heading' => BookPart::SeriesPage->title($language),
                'series' => $book->series_name,
                'books' => $series->map(fn (Book $b) => [
                    'id' => $b->id, 'title' => $b->title, 'subtitle' => $b->subtitle, 'number' => $b->series_number,
                ])->values()->all(),
            ];
        }

        $others = self::alsoBy($book);

        if ($others->isNotEmpty()) {
            return [
                'kind' => 'also_by',
                'heading' => self::fill($words['also_by'], ['author' => (string) $book->author_name]),
                'series' => null,
                'books' => $others->map(fn (Book $b) => [
                    'id' => $b->id, 'title' => $b->title, 'subtitle' => $b->subtitle, 'number' => null,
                ])->values()->all(),
            ];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function sectionPage(Book $book, ManuscriptSection $section, int $depth, string $language): array
    {
        $part = $section->bookPart();
        $group = self::placement($section)[0];

        $state = match (true) {
            $section->type === 'part', $part?->isGenerated() && $part !== BookPart::CopyrightPage => 'ready',
            BookPartTemplates::hasGuidance($section->content) => 'placeholder',
            trim($section->plainText()) === '' => 'empty',
            default => 'ready',
        };

        $base = [
            'source' => $part?->isGenerated() && $part !== BookPart::CopyrightPage ? 'generated' : 'author',
            'section_id' => $section->id,
            'depth' => $depth,
            'title' => $section->title,
            'state' => $state,
            'data' => match ($part) {
                BookPart::ListOfIllustrations => ['list' => 'figure'],
                BookPart::ListOfTables => ['list' => 'table'],
                BookPart::TableOfContents => ['heading' => $section->title],
                default => null,
            },
        ];

        if ($part) {
            return self::page($part, $base + ['group' => $part->groupFor($section->type)]);
        }

        // Nobody has named this section: treat it by its type.
        return $base + [
            'part' => null,
            'group' => $group,
            'start' => $group === 'front' ? 'recto' : 'any',
            'folio' => $group === 'front' ? 'roman' : 'arabic',
            'show_folio' => $section->type !== 'part',
            'running_head' => $section->type !== 'part',
            'numbered' => $section->type === 'chapter',
            'restart_folio' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function page(BookPart $part, array $extra): array
    {
        return $extra + [
            'part' => $part->value,
            'group' => $part->group(),
            'section_id' => null,
            'depth' => 0,
            'start' => $part->start(),
            'folio' => $part->folio(),
            'restart_folio' => false,
            'show_folio' => $part->showsFolio(),
            'running_head' => $part->hasRunningHead(),
            'numbered' => $part->isNumbered(),
            'state' => 'ready',
            'data' => null,
        ];
    }

    /**
     * A top-level section's group and conventional order. Unnamed sections
     * go by their type.
     *
     * @return array{0: string, 1: int}
     */
    public static function placement(ManuscriptSection $section): array
    {
        $part = $section->bookPart();

        if ($part) {
            return [$part->groupFor($section->type), $part->orderFor($section->type)];
        }

        return match ($section->type) {
            'front_matter' => ['front', self::UNNAMED_FRONT_ORDER],
            'back_matter' => ['back', self::UNNAMED_BACK_ORDER],
            default => ['body', 200],
        };
    }

    /**
     * The Book parts checklist: every conventional part, whether the book
     * has it, and whether this kind of book is expected to.
     *
     * state: present | empty | placeholder | generated | missing | unavailable
     *
     * @return array<string, mixed>
     */
    public static function checklist(Book $book): array
    {
        $language = self::language($book);
        $sections = $book->sections()->orderBy('position')->get();
        $byPart = $sections->filter(fn ($s) => $s->part)->groupBy('part');
        $recommended = self::recommendations($book);

        $alternatives = [];
        foreach ($recommended as $set) {
            foreach ($set as $part) {
                $alternatives[$part->value] = array_map(fn (BookPart $p) => $p->value, $set);
            }
        }

        $rows = [];

        foreach (BookPart::ordered() as $part) {
            $mine = $byPart->get($part->value, collect());
            [$state, $issues] = self::stateOf($book, $part, $mine);

            $rows[] = [
                'key' => $part->value,
                'name' => $part->title($language),
                'english' => $part->title('en'),
                'chicago' => $part->chicagoName(),
                'group' => $part->group(),
                'source' => $part->source(),
                'state' => $state,
                'issues' => $issues,
                'count' => $mine->count(),
                'section_ids' => $mine->pluck('id')->values()->all(),
                'recommended' => isset($alternatives[$part->value]),
                'alternatives' => array_values(array_diff($alternatives[$part->value] ?? [], [$part->value])),
                'can_add' => BookPartTemplates::has($part)
                    && ($mine->isEmpty() || in_array($part->value, self::REPEATABLE, true)),
            ];
        }

        $satisfied = fn (array $set) => collect($rows)
            ->whereIn('key', array_map(fn (BookPart $p) => $p->value, $set))
            ->contains(fn ($row) => in_array($row['state'], ['present', 'generated'], true));

        $preview = self::reorderPreview($book);

        return [
            'profile' => self::profile($book),
            'language' => $language,
            'parts' => $rows,
            'missing_recommended' => collect($recommended)->reject($satisfied)->count(),
            'unclassified' => $sections
                ->filter(fn ($s) => ! $s->part && $s->type !== 'section')
                ->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'type' => $s->type])
                ->values()->all(),
            'in_order' => $preview['moves'] === [],
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    private static function stateOf(Book $book, BookPart $part, Collection $mine): array
    {
        $issues = [];

        if ($part === BookPart::BackCoverCopy) {
            $copy = trim((string) ($book->description ?: ($book->meta['description'] ?? '')));

            return [$copy !== '' ? 'present' : 'missing', $copy !== '' ? [] : ['Write the book description; it is the back-cover copy.']];
        }

        if ($part === BookPart::CopyrightPage && $mine->isEmpty()) {
            if (! self::copyrightHolder($book)) {
                $issues[] = 'No copyright holder: set an author name, a publisher, or a copyright holder.';
            }

            if (! $book->publication()['copyright_year']) {
                $issues[] = 'No copyright year.';
            }

            return ['generated', $issues];
        }

        if ($mine->isEmpty()) {
            return match ($part) {
                BookPart::HalfTitle, BookPart::TitlePage, BookPart::TableOfContents => ['generated', []],
                BookPart::SeriesPage => self::seriesBooks($book)->isNotEmpty() || self::alsoBy($book)->isNotEmpty()
                    ? ['generated', []]
                    : ['unavailable', ['Set a series name shared with your other books, or publish another book, to generate this page.']],
                default => ['missing', []],
            };
        }

        if ($part->isGenerated() && $part !== BookPart::CopyrightPage) {
            return ['generated', []];
        }

        if ($mine->contains(fn ($s) => BookPartTemplates::hasGuidance($s->content))) {
            return ['placeholder', ['Still contains the template’s instructions.']];
        }

        if ($part !== BookPart::Part && $mine->every(fn ($s) => trim($s->plainText()) === '')) {
            return ['empty', []];
        }

        return ['present', []];
    }

    /**
     * What reorder() would do, without doing it: the top-level sections in
     * conventional order, and which ones move.
     *
     * Front and back matter sort into Chicago order; parts and chapters keep
     * the author's order; a conclusion, epilogue or afterword goes after
     * them. Ties keep the binder order. A section nobody has named travels
     * with the named section before it.
     *
     * @return array{fingerprint: string, moves: list<array<string, mixed>>, order: list<array<string, mixed>>}
     */
    public static function reorderPreview(Book $book): array
    {
        $roots = $book->sections()->whereNull('parent_id')->orderBy('position')->orderBy('id')->get()->values();

        $rank = ['front' => 0, 'body' => 1, 'back' => 2];
        $keyed = [];
        $carry = ['front' => self::UNNAMED_FRONT_ORDER, 'body' => 200, 'back' => self::UNNAMED_BACK_ORDER];

        foreach ($roots as $index => $section) {
            [$group, $order] = self::placement($section);

            if ($section->bookPart()) {
                $carry[$group] = $order;
            } else {
                $order = $carry[$group];
            }

            $keyed[] = ['section' => $section, 'key' => [$rank[$group], $order, $index], 'from' => $index];
        }

        $sorted = $keyed;
        usort($sorted, fn ($a, $b) => $a['key'] <=> $b['key']);

        $order = [];
        $moves = [];

        foreach ($sorted as $to => $item) {
            $section = $item['section'];
            $row = [
                'id' => $section->id,
                'title' => $section->title,
                'part' => $section->part,
                'from' => $item['from'] + 1,
                'to' => $to + 1,
            ];
            $order[] = $row;

            if ($item['from'] !== $to) {
                $moves[] = $row;
            }
        }

        return [
            'fingerprint' => self::fingerprint($roots),
            'moves' => $moves,
            'order' => $order,
        ];
    }

    /**
     * Put the top-level sections in conventional order.
     *
     * Takes the fingerprint of the preview the author saw: if the binder
     * changed since, nothing moves and the caller shows the new preview.
     * Only positions change — no title, content or updated_at.
     *
     * @return bool false when the preview was stale
     */
    public static function reorder(Book $book, string $fingerprint): bool
    {
        return DB::transaction(function () use ($book, $fingerprint) {
            $book->sections()->whereNull('parent_id')->lockForUpdate()->get(['id']);

            $preview = self::reorderPreview($book);

            if (! hash_equals($preview['fingerprint'], $fingerprint)) {
                return false;
            }

            self::renumber(array_column($preview['order'], 'id'));

            return true;
        });
    }

    /**
     * Add a part from its template, at its conventional place.
     *
     * Refuses a part the book already has (unless it may repeat) and parts
     * only the app can generate.
     */
    public static function add(Book $book, BookPart $part): ManuscriptSection
    {
        if (! BookPartTemplates::has($part)) {
            throw new InvalidArgumentException("The {$part->chicagoName()} is generated from the book's details and cannot be added as a section.");
        }

        if (! in_array($part->value, self::REPEATABLE, true) && $book->sections()->where('part', $part->value)->exists()) {
            throw new InvalidArgumentException("This book already has a {$part->chicagoName()}.");
        }

        return DB::transaction(function () use ($book, $part) {
            $roots = $book->sections()->whereNull('parent_id')->orderBy('position')->orderBy('id')
                ->lockForUpdate()->get()->values();

            $template = BookPartTemplates::for($book, $part);
            $section = $book->sections()->create([
                'type' => $template['type'],
                'part' => $template['part'],
                'title' => $template['title'],
                'content' => $template['content'],
                'position' => 0,
            ]);
            $section->forceFill([
                'word_count' => ManuscriptSection::countWords($section->content),
            ])->saveQuietly();

            // Insert before the first section that conventionally follows it.
            $rank = ['front' => 0, 'body' => 1, 'back' => 2];
            [$group, $order] = self::placement($section);
            $mine = [$rank[$group], $order];

            $ids = $roots->pluck('id')->all();
            $at = count($ids);

            foreach ($roots as $index => $other) {
                [$g, $o] = self::placement($other);

                if ([$rank[$g], $o] > $mine) {
                    $at = $index;
                    break;
                }
            }

            array_splice($ids, $at, 0, [$section->id]);
            self::renumber($ids);

            $book->recalculateWordCount();

            return $section->refresh();
        });
    }

    /**
     * Name a section's part from the binder. The section's type follows,
     * so a chapter marked as the Introduction stops taking a chapter number.
     */
    public static function assign(ManuscriptSection $section, ?BookPart $part): ManuscriptSection
    {
        if ($part === null) {
            $section->forceFill(['part' => null])->save();

            return $section;
        }

        if (! $part->isSectionKind()) {
            throw new InvalidArgumentException('That part is not a manuscript section.');
        }

        $type = $section->type === 'section' ? 'section' : $part->sectionType();

        if ($section->type === 'part' && $type !== 'part' && $section->children()->exists()) {
            throw new InvalidArgumentException('This part holds chapters. Move them out before changing what it is.');
        }

        if ($type === 'part' && $section->parent_id) {
            throw new InvalidArgumentException('A part cannot sit inside another section.');
        }

        $section->forceFill(['part' => $part->value, 'type' => $type])->save();

        return $section;
    }

    /** Sparse positions in the given order, without touching updated_at. */
    private static function renumber(array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            ManuscriptSection::whereKey($id)->toBase()
                ->update(['position' => ($index + 1) * ManuscriptSection::POSITION_STEP]);
        }
    }

    private static function fingerprint(Collection $roots): string
    {
        return sha1($roots->pluck('id')->implode(','));
    }

    private static function fill(string $pattern, array $values): string
    {
        $replace = [];
        foreach ($values as $key => $value) {
            $replace[':'.$key] = (string) $value;
        }

        // Longest first, so ":name" never eats part of a longer key.
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));

        return strtr($pattern, $replace);
    }
}
