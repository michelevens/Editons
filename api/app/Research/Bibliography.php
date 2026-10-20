<?php

namespace App\Research;

use App\Models\Book;
use App\Models\Citation;
use App\Models\ResearchSource;
use Illuminate\Support\Collection;

/**
 * Formats a book's sources as a bibliography, in the style its author chose.
 *
 * Server-side because a citation style is a publishing rule, not a display
 * preference: the same entry has to come out identically here, in an export,
 * and in anything that later checks a manuscript against its references.
 * Three implementations of it on three clients would be three chances to
 * disagree.
 *
 * Each style follows its manual's common cases — journal article, book,
 * report and web page — closely enough to hand an examiner or a publisher.
 * They are not a substitute for the manual on the unusual ones, and the
 * screen says so rather than implying the output is authoritative.
 *
 * Entries are built with *asterisks* around italic runs, then rendered twice:
 * once as plain text for copying, once as HTML for display and export. One
 * builder, so the two can never disagree about anything but the italics.
 */
class Bibliography
{
    /** Style key => the name an author recognises. */
    public const NAMES = [
        'APA' => 'APA 7th',
        'Chicago' => 'Chicago author-date (17th)',
        'Chicago-notes' => 'Chicago notes and bibliography (17th)',
        'MLA' => 'MLA 9th',
        'Harvard' => 'Harvard',
        'IEEE' => 'IEEE',
        'Vancouver' => 'Vancouver',
    ];

    public const STYLES = ['APA', 'Chicago', 'Chicago-notes', 'MLA', 'Harvard', 'IEEE', 'Vancouver'];

    /** Styles whose list is numbered in order of first citation. */
    public const NUMERIC = ['IEEE', 'Vancouver'];

    /**
     * Whether the source's author list was cut short with "et al.".
     *
     * Papers with dozens of authors are routinely recorded that way, and a
     * style must then never join the last listed name with "and" as if it
     * were the last author. Set per entry by names().
     */
    private bool $more = false;

    public static function normalise(?string $style): string
    {
        return in_array($style, self::STYLES, true) ? $style : 'APA';
    }

    /** @return array<int, array{value: string, label: string, numeric: bool}> */
    public static function options(): array
    {
        return array_map(fn (string $style) => [
            'value' => $style,
            'label' => self::NAMES[$style],
            'numeric' => in_array($style, self::NUMERIC, true),
        ], self::STYLES);
    }

    /**
     * Every source, in the order the style reads them.
     *
     * Author-date and notes styles sort alphabetically, by author and then
     * title. Numeric styles number sources in the order the manuscript first
     * cites them — chapter by chapter, then citation by citation — and put
     * anything never cited at the end, still numbered, so nothing silently
     * drops out of the list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBook(Book $book, string $style = 'APA'): array
    {
        $style = self::normalise($style);
        $sources = $book->sources()->get();

        $sources = in_array($style, self::NUMERIC, true)
            ? $this->byFirstCitation($book, $sources)
            : $sources->sortBy(fn (ResearchSource $source) => mb_strtolower(
                ($source->authors ?: $source->title).' '.$this->year($source),
            ))->values();

        return $sources
            ->values()
            ->map(function (ResearchSource $source, int $index) use ($style) {
                $marked = $this->marked($source, $style, $index + 1);

                return [
                    'id' => $source->id,
                    'number' => $index + 1,
                    'title' => $source->title,
                    'verification' => $source->verification,
                    'entry' => self::plain($marked),
                    'html' => self::html($marked),
                    'in_text' => $this->inText($source, $style, $index + 1),
                ];
            })
            ->all();
    }

    /** One entry, in the requested style, as plain text. */
    public function format(ResearchSource $source, string $style = 'APA', int $number = 1): string
    {
        return self::plain($this->marked($source, self::normalise($style), $number));
    }

    /** One entry as HTML, italics included. */
    public function formatHtml(ResearchSource $source, string $style = 'APA', int $number = 1): string
    {
        return self::html($this->marked($source, self::normalise($style), $number));
    }

    /** The plain-text bibliography, for copying. */
    public function asText(Book $book, string $style = 'APA'): string
    {
        return collect($this->forBook($book, $style))
            ->pluck('entry')
            ->implode("\n");
    }

    /**
     * How the source is cited in the running text.
     *
     * "(Smith & Lee, 2021)" in APA, "(Smith and Lee 2021)" in Chicago,
     * "(Smith and Lee)" in MLA, "[3]" in IEEE. Notes style returns the short
     * note a footnote would carry.
     */
    public function inText(ResearchSource $source, string $style = 'APA', int $number = 1): string
    {
        $style = self::normalise($style);
        $surnames = array_map(fn (array $name) => $name['last'], $this->names($source));
        $year = $this->year($source);
        $short = $this->shortTitle($source);

        $who = fn (string $and, int $etAl, bool $serial = true) => match (true) {
            $surnames === [] => $short,
            $this->more, count($surnames) >= $etAl => $surnames[0].' et al.',
            count($surnames) === 1 => $surnames[0],
            count($surnames) === 2 => "{$surnames[0]} {$and} {$surnames[1]}",
            default => implode(', ', array_slice($surnames, 0, -1)).($serial ? ',' : '')." {$and} ".end($surnames),
        };

        return match ($style) {
            'IEEE' => "[{$number}]",
            'Vancouver' => "({$number})",
            'Chicago' => '('.$who('and', 4)." {$year})",
            'Chicago-notes' => ($surnames ? $who('and', 4).', ' : '').$short.'.',
            'MLA' => '('.$who('and', 3).')',
            'Harvard' => '('.$who('and', 4, false).", {$year})",
            default => '('.$who('&', 3).", {$year})",
        };
    }

    /** The entry with *italic* runs marked. */
    private function marked(ResearchSource $source, string $style, int $number): string
    {
        $names = $this->names($source);
        $title = $this->clean($source->title);
        $year = $this->year($source);
        $container = $this->clean((string) $source->container);
        $publisher = $this->clean((string) $source->publisher);
        $volume = trim((string) $source->volume);
        $issue = trim((string) $source->issue);
        $pages = str_replace('-', '–', trim((string) $source->pages));
        $edition = trim((string) $source->edition);
        $link = $this->link($source);
        $article = $source->kind === 'journal_article' || ($container && $source->kind !== 'book');

        $entry = match ($style) {
            'Chicago' => $this->chicago($names, $title, $year, $container, $publisher, $volume, $issue, $pages, $edition, $article),
            'Chicago-notes' => $this->chicagoNotes($names, $title, $year, $container, $publisher, $volume, $issue, $pages, $edition, $article),
            'MLA' => $this->mla($names, $title, $year, $container, $publisher, $volume, $issue, $pages, $edition, $article),
            'Harvard' => $this->harvard($names, $title, $year, $container, $publisher, $volume, $issue, $pages, $edition, $article),
            'IEEE' => "[{$number}] ".$this->ieee($names, $title, $year, $container, $publisher, $volume, $issue, $pages, $edition, $article),
            'Vancouver' => "{$number}. ".$this->vancouver($names, $title, $year, $container, $publisher, $volume, $issue, $pages, $edition, $article),
            default => $this->apa($names, $title, $year, $container, $publisher, $volume, $issue, $pages, $edition, $article),
        };

        if ($link) {
            $entry .= match ($style) {
                'IEEE' => $source->doi ? ", doi: {$this->doi($source)}." : ". [Online]. Available: {$link}",
                'Harvard' => $source->doi ? " doi: {$this->doi($source)}." : " Available at: {$link}.",
                'Vancouver' => $source->doi ? " doi:{$this->doi($source)}" : " Available from: {$link}",
                default => " {$link}",
            };
        } elseif ($style === 'IEEE') {
            $entry .= '.';
        }

        return preg_replace('/\.\.+/', '.', preg_replace('/\s+/', ' ', $entry));
    }

    /** @param  array<int, array{first: string, last: string, initials: string, corporate: bool}>  $names */
    private function apa(array $names, string $title, string $year, string $container, string $publisher, string $volume, string $issue, string $pages, string $edition, bool $article): string
    {
        $listed = array_map(fn ($n) => $n['corporate'] ? $n['last'] : "{$n['last']}, {$n['initials']}", $names);
        $authors = $this->more ? implode(', ', $listed).', et al.' : $this->join($listed, ', ', ', & ', 20);
        $date = "({$year}).";
        $lead = $authors ? $this->stop($authors)." {$date}" : '';

        if ($article) {
            $where = $container ? " *{$container}*".($volume ? ", *{$volume}*" : '').($issue ? "({$issue})" : '').($pages ? ", {$pages}" : '').'.' : '';

            return $authors ? "{$lead} {$title}.{$where}" : "{$title}. {$date}{$where}";
        }

        $ed = $edition ? ' ('.$this->ordinal($edition).' ed.)' : '';
        $body = "*{$title}*{$ed}.".($publisher ? " {$publisher}." : '');

        return $authors ? "{$lead} {$body}" : "{$body} {$date}";
    }

    private function chicago(array $names, string $title, string $year, string $container, string $publisher, string $volume, string $issue, string $pages, string $edition, bool $article): string
    {
        $authors = $this->invertFirst($names, 'and', 10);
        $lead = $authors ? $this->stop($authors)." {$year}." : '';

        if ($article) {
            $where = $container ? " *{$container}*".($volume ? " {$volume}" : '').($issue ? " ({$issue})" : '').($pages ? ": {$pages}" : '').'.' : '';

            return $authors ? "{$lead} “{$title}.”{$where}" : "“{$title}.” {$year}.{$where}";
        }

        $ed = $edition ? ' '.$this->ordinal($edition).' ed.' : '';
        $body = "*{$title}*.{$ed}".($publisher ? " {$publisher}." : '');

        return $authors ? "{$lead} {$body}" : "{$body} {$year}.";
    }

    private function chicagoNotes(array $names, string $title, string $year, string $container, string $publisher, string $volume, string $issue, string $pages, string $edition, bool $article): string
    {
        $authors = $this->invertFirst($names, 'and', 10);
        $lead = $authors ? $this->stop($authors).' ' : '';

        if ($article) {
            $where = $container
                ? " *{$container}*".($volume ? " {$volume}" : '').($issue ? ", no. {$issue}" : '')." ({$year})".($pages ? ": {$pages}" : '').'.'
                : " {$year}.";

            return "{$lead}“{$title}.”{$where}";
        }

        $ed = $edition ? ' '.$this->ordinal($edition).' ed.' : '';

        return "{$lead}*{$title}*.{$ed} ".($publisher ? "{$publisher}, " : '')."{$year}.";
    }

    private function mla(array $names, string $title, string $year, string $container, string $publisher, string $volume, string $issue, string $pages, string $edition, bool $article): string
    {
        $authors = match (true) {
            $names === [] => '',
            $this->more, count($names) >= 3 => $this->inverted($names[0]).', et al',
            count($names) === 2 => $this->inverted($names[0]).', and '.$this->natural($names[1]),
            default => $this->inverted($names[0]),
        };
        $lead = $authors ? $this->stop($authors).' ' : '';
        $year = $year === 'n.d.' ? '' : $year;

        if ($article) {
            $parts = array_filter([
                $container ? "*{$container}*" : '',
                $volume ? "vol. {$volume}" : '',
                $issue ? "no. {$issue}" : '',
                $year,
                $pages ? (str_contains($pages, '–') ? "pp. {$pages}" : "p. {$pages}") : '',
            ]);

            return "{$lead}“{$title}.” ".implode(', ', $parts).'.';
        }

        $parts = array_filter([$edition ? $this->ordinal($edition).' ed.' : '', $publisher, $year]);

        return "{$lead}*{$title}*. ".implode(', ', $parts).'.';
    }

    private function harvard(array $names, string $title, string $year, string $container, string $publisher, string $volume, string $issue, string $pages, string $edition, bool $article): string
    {
        $listed = array_map(fn ($n) => $n['corporate'] ? $n['last'] : "{$n['last']}, {$n['initials']}", $names);
        $authors = $this->more || count($listed) >= 4 ? $listed[0].' et al.' : $this->join($listed, ', ', ' and ', 3);
        $lead = ($authors ?: $title)." ({$year})";

        if ($article) {
            $where = $container ? ", *{$container}*".($volume ? ", {$volume}" : '').($issue ? "({$issue})" : '').($pages ? ", pp. {$pages}" : '') : '';

            return $authors ? "{$lead} ‘{$title}’{$where}." : "{$lead}{$where}.";
        }

        $ed = $edition ? ' '.$this->ordinal($edition).' edn.' : '';

        return ($authors ? "{$lead} *{$title}*." : "{$lead}.").$ed.($publisher ? " {$publisher}." : '');
    }

    private function ieee(array $names, string $title, string $year, string $container, string $publisher, string $volume, string $issue, string $pages, string $edition, bool $article): string
    {
        $listed = array_map(fn ($n) => $n['corporate'] ? $n['last'] : trim("{$n['initials']} {$n['last']}"), $names);
        $authors = $this->more || count($listed) > 6 ? $listed[0].' et al.' : $this->join($listed, ', ', count($listed) > 2 ? ', and ' : ' and ', 6);
        $lead = $authors ? "{$authors}, " : '';
        $year = $year === 'n.d.' ? '' : $year;

        if ($article) {
            $parts = array_filter([
                $container ? "*{$container}*" : '',
                $volume ? "vol. {$volume}" : '',
                $issue ? "no. {$issue}" : '',
                $pages ? "pp. {$pages}" : '',
                $year,
            ]);

            return "{$lead}“{$title},” ".implode(', ', $parts);
        }

        $ed = $edition ? ', '.$this->ordinal($edition).' ed' : '';

        return "{$lead}*{$title}*{$ed}. ".implode(', ', array_filter([$publisher, $year]));
    }

    private function vancouver(array $names, string $title, string $year, string $container, string $publisher, string $volume, string $issue, string $pages, string $edition, bool $article): string
    {
        $listed = array_map(fn ($n) => $n['corporate'] ? $n['last'] : trim($n['last'].' '.str_replace(['.', ' '], '', $n['initials'])), $names);
        $authors = $this->more || count($listed) > 6 ? implode(', ', array_slice($listed, 0, 6)).', et al' : implode(', ', $listed);
        $lead = $authors ? "{$authors}. " : '';

        if ($article) {
            $where = $container ? " {$container}. {$year}".($volume ? ";{$volume}" : '').($issue ? "({$issue})" : '').($pages ? ":{$pages}" : '').'.' : " {$year}.";

            return "{$lead}{$title}.{$where}";
        }

        $ed = $edition ? ' '.$this->ordinal($edition).' ed.' : '';

        return "{$lead}{$title}.{$ed} ".($publisher ? "{$publisher}; " : '')."{$year}.";
    }

    /**
     * The authors field, split into people.
     *
     * Authors arrive as a person types them: "Smith, J., & Lee, K.",
     * "Jane Smith and Kofi Lee", "Smith, Jane; Lee, Kofi", or an organisation
     * like "World Health Organization". Semicolons are the unambiguous
     * separator; failing that, "&" and "and"; failing that, the "Last,
     * Initials., Last, Initials." run APA itself produces.
     *
     * @return array<int, array{first: string, last: string, initials: string, corporate: bool}>
     */
    private function names(ResearchSource $source): array
    {
        $raw = trim((string) $source->authors);
        $this->more = (bool) preg_match('/[,;]?\s*\bet\s+al\.?$/iu', $raw);
        $raw = trim(preg_replace('/[,;]?\s*\bet\s+al\.?$/iu', '', $raw));

        if ($raw === '') {
            return [];
        }

        if (str_contains($raw, ';')) {
            $people = explode(';', $raw);
        } else {
            $people = preg_split('/\s*,?\s*(?:&|\band\b)\s*/u', $raw);
            $people = collect($people)->flatMap(
                // "Smith, J., Lee, K." — a comma after initials starts the
                // next person.
                fn ($chunk) => preg_split('/(?<=\.),\s+(?=[\p{Lu}][\p{L}\'’-]+,)/u', $chunk)
            )->all();
        }

        return collect($people)
            ->map(fn ($person) => trim($person, " ,;\t"))
            ->filter()
            ->map(fn ($person) => $this->person($person))
            ->values()
            ->all();
    }

    /** @return array{first: string, last: string, initials: string, corporate: bool} */
    private function person(string $person): array
    {
        if (str_contains($person, ',')) {
            [$last, $first] = array_map('trim', explode(',', $person, 2));
        } else {
            $words = preg_split('/\s+/u', $person);

            // One word, or anything that reads as an institution, is kept
            // whole rather than inverted into "Organization, W. H.".
            if (count($words) === 1 || count($words) > 4 || preg_match('/\b(?:of|for|the|Inc|Ltd|LLC|Institute|Organization|Organisation|Association|Society|University|Agency|Bank|Council|Department|Office|Group|Foundation|Commission|Centre|Center)\b/u', $person)) {
                return ['first' => '', 'last' => $person, 'initials' => '', 'corporate' => true];
            }

            $last = array_pop($words);
            $first = implode(' ', $words);
        }

        $initials = collect(preg_split('/[\s.]+/u', $first, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($part) => collect(explode('-', $part))
                ->map(fn ($piece) => mb_substr($piece, 0, 1).'.')
                ->implode('-'))
            ->implode(' ');

        return ['first' => $first, 'last' => $last, 'initials' => $initials, 'corporate' => false];
    }

    /** "Smith, Jane, and Kofi Lee" — the Chicago shape. */
    private function invertFirst(array $names, string $and, int $max): string
    {
        if ($names === []) {
            return '';
        }

        if ($this->more || count($names) > $max) {
            // Chicago lists the first seven, then et al.
            $listed = [$this->inverted($names[0])];

            foreach (array_slice($names, 1, 6) as $name) {
                $listed[] = $this->natural($name);
            }

            return implode(', ', $listed).', et al.';
        }

        $listed = [$this->inverted($names[0])];

        foreach (array_slice($names, 1) as $name) {
            $listed[] = $this->natural($name);
        }

        return $this->join($listed, ', ', count($listed) > 2 ? ", {$and} " : ", {$and} ", $max);
    }

    private function inverted(array $name): string
    {
        return $name['corporate'] || $name['first'] === '' ? $name['last'] : "{$name['last']}, {$name['first']}";
    }

    private function natural(array $name): string
    {
        return trim("{$name['first']} {$name['last']}");
    }

    /** @param  array<int, string>  $items */
    private function join(array $items, string $separator, string $last, int $max): string
    {
        $items = array_values(array_filter($items));

        if (count($items) > $max) {
            $items = [...array_slice($items, 0, $max - 1), '… '.end($items)];
        }

        return match (count($items)) {
            0 => '',
            1 => $items[0],
            default => implode($separator, array_slice($items, 0, -1)).$last.end($items),
        };
    }

    /** IEEE and Vancouver list sources in order of first citation. */
    private function byFirstCitation(Book $book, Collection $sources): Collection
    {
        $positions = $book->sections()->pluck('position', 'id');

        $first = Citation::whereIn('manuscript_section_id', $positions->keys())
            ->whereNotNull('research_source_id')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Citation $c) => [$positions[$c->manuscript_section_id] ?? PHP_INT_MAX, $c->id])
            ->pluck('research_source_id')
            ->unique()
            ->values()
            ->flip();

        return $sources->sortBy(fn (ResearchSource $source) => [
            $first[$source->id] ?? PHP_INT_MAX,
            mb_strtolower($source->authors ?: $source->title),
        ])->values();
    }

    private function year(ResearchSource $source): string
    {
        $published = trim((string) $source->published);

        if (preg_match('/\b(1[5-9]\d{2}|20\d{2})[a-z]?\b/', $published, $match)) {
            return $match[0];
        }

        return $published !== '' && mb_strlen($published) <= 12 ? $published : 'n.d.';
    }

    private function shortTitle(ResearchSource $source): string
    {
        $title = $this->clean(preg_split('/[:?]/u', $source->title)[0]);
        $words = preg_split('/\s+/u', $title);

        return count($words) > 4 ? implode(' ', array_slice($words, 0, 4)) : $title;
    }

    private function link(ResearchSource $source): string
    {
        // A DOI is how a reader finds the thing, so it outranks a URL when
        // both are present.
        if ($source->doi) {
            return 'https://doi.org/'.$this->doi($source);
        }

        return (string) $source->url;
    }

    private function doi(ResearchSource $source): string
    {
        return preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i', '', ltrim(trim($source->doi), '/'));
    }

    private function clean(string $text): string
    {
        return rtrim(trim(str_replace('*', '', $text)), '.');
    }

    private function stop(string $text): string
    {
        return str_ends_with($text, '.') ? $text : "{$text}.";
    }

    private function ordinal(string $edition): string
    {
        if (! ctype_digit($edition)) {
            return preg_replace('/\s*(?:ed|edn|edition)\.?$/i', '', $edition);
        }

        $n = (int) $edition;
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return "{$n}{$suffix}";
    }

    private static function plain(string $marked): string
    {
        return str_replace('*', '', $marked);
    }

    private static function html(string $marked): string
    {
        return preg_replace('/\*([^*]+)\*/u', '<i>$1</i>', e($marked, false));
    }
}
