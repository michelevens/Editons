<?php

namespace App\Journey;

/**
 * A book proposal's outline, read for its structure.
 *
 * The plain importer treats every line as a chapter title, which is right
 * for a list typed into a notes app and wrong for the outline an author
 * actually brings from a proposal or a document: "## Part I — ..." headings,
 * "Chapter 1: ..." lines, bullet points of what each chapter covers, a line
 * describing each part, and front material (subtitle, audience, length).
 * Pasted into the plain importer, a 24-chapter proposal became several
 * hundred "chapters", one per bullet and table row, and no parts.
 *
 * This reads the markers. Parts and chapters become the binder; each part's
 * description becomes its subtitle; the bullets under a chapter are its
 * plan, which goes to the book's notes rather than into the chapter, because
 * any words in a chapter mark it written and the journey would report the
 * book drafted. Appendix headings become back matter, one section per item.
 * Everything else — the proposal's front material, editorial direction,
 * tables — is kept verbatim in the notes, so nothing pasted is lost.
 */
class StructuredOutline
{
    private const PART = '/^(?:#+\s*)?part\s+([IVXLC]+|\d+)\b\s*[:.\-–—]?\s*(.*)$/iu';

    private const CHAPTER = '/^(?:#+\s*)?chapter\s+(\d+)\b\s*[:.\-–—]?\s*(.*)$/iu';

    private const BULLET = '/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/u';

    private const HEADING = '/^(#+)\s*(.*)$/u';

    /** Whether the text has the markers this reads; otherwise it is a plain list. */
    public static function looksStructured(string $outline): bool
    {
        $chapters = preg_match_all('/^\s*(?:#+\s*)?chapter\s+\d+\b/imu', $outline);

        return $chapters >= 2;
    }

    /**
     * @return array{
     *     sections: array<int, array{type: string, title: string, subtitle: ?string, part: ?int}>,
     *     title: ?string,
     *     subtitle: ?string,
     *     audience: ?string,
     *     notes: string,
     * }
     */
    public function parse(string $outline): array
    {
        $sections = [];
        $notes = [];
        $plan = [];
        $title = null;
        $subtitle = null;
        $audience = null;

        $part = null;           // index in $sections of the current part
        $chapter = null;        // index of the current chapter
        $appendix = false;      // inside an appendices heading
        $partOpen = false;      // a part was just opened; its first plain line describes it
        $seenStructure = false;

        foreach (preg_split('/\r\n|\r|\n/', $outline) ?: [] as $raw) {
            $line = trim($raw);

            if ($line === '') {
                continue;
            }

            if (preg_match(self::PART, $line, $m)) {
                $sections[] = ['type' => 'part', 'title' => $this->title($m[2], "Part {$m[1]}"), 'subtitle' => null, 'part' => null];
                $part = array_key_last($sections);
                $chapter = null;
                $appendix = false;
                $partOpen = true;
                $seenStructure = true;

                continue;
            }

            if (preg_match(self::CHAPTER, $line, $m)) {
                $name = $this->title($m[2], "Chapter {$m[1]}");
                $sections[] = ['type' => 'chapter', 'title' => $name, 'subtitle' => null, 'part' => $part];
                $chapter = array_key_last($sections);
                $plan[$chapter] = ["Chapter {$m[1]}: {$name}"];
                $appendix = false;
                $partOpen = false;
                $seenStructure = true;

                continue;
            }

            if (preg_match(self::HEADING, $line, $m)) {
                $text = trim($m[2]);
                $chapter = null;
                $partOpen = false;

                // The first top-level heading before any structure is the
                // book's title, and the outline's own "BOOK OUTLINE" label
                // is not.
                if (! $seenStructure && $m[1] === '#' && $title === null && ! preg_match('/^(book\s+)?outline$/i', $text)) {
                    $title = $text;

                    continue;
                }

                if (preg_match('/appendi(x|ces)/iu', $text)) {
                    $appendix = true;
                    $part = null;
                    $notes[] = "\n{$text}";

                    continue;
                }

                $appendix = false;

                // Any other heading after the parts begin closes the last
                // part: what follows is about the book, not in it.
                if ($seenStructure) {
                    $part = null;
                }

                $notes[] = "\n{$text}";

                continue;
            }

            if ($appendix && preg_match(self::BULLET, $line, $m)) {
                $sections[] = ['type' => 'back_matter', 'title' => $this->title($m[1], 'Appendix'), 'subtitle' => null, 'part' => null];

                continue;
            }

            if ($chapter !== null) {
                $plan[$chapter][] = preg_match(self::BULLET, $line, $m) ? '- '.trim($m[1]) : $line;

                continue;
            }

            if ($partOpen && $part !== null && ! preg_match(self::BULLET, $line)) {
                $sections[$part]['subtitle'] = mb_substr($line, 0, 255);
                $partOpen = false;

                continue;
            }

            // Front material: "Subtitle: ..." and "Target audience: ..." are
            // fields the book has; the rest is kept as written.
            if (! $seenStructure && preg_match('/^subtitle\s*:\s*(.+)$/iu', $line, $m)) {
                $subtitle = trim($m[1]);
            } elseif (! $seenStructure && preg_match('/^(?:target\s+)?audience\s*:\s*(.+)$/iu', $line, $m)) {
                $audience = trim($m[1]);
            }

            // Paragraphs keep the blank line between them; table rows and
            // list items stay together.
            $notes[] = preg_match('/^[|]/', $line) || preg_match(self::BULLET, $line) ? $line : "\n{$line}";
        }

        return [
            'sections' => $sections,
            'title' => $title,
            'subtitle' => $subtitle,
            'audience' => $audience,
            'notes' => $this->notes($plan, $notes),
        ];
    }

    /** The chapter plans first, then everything else, as plain text. */
    private function notes(array $plan, array $other): string
    {
        $out = [];

        if ($plan !== []) {
            $out[] = 'CHAPTER PLAN (imported from the outline)';

            foreach ($plan as $lines) {
                $out[] = '';
                array_push($out, ...$lines);
            }
        }

        $rest = trim(implode("\n", $other));

        if ($rest !== '') {
            $out[] = '';
            $out[] = 'FROM THE OUTLINE';
            $out[] = '';
            $out[] = $rest;
        }

        return trim(implode("\n", $out));
    }

    private function title(string $text, string $fallback): string
    {
        $text = trim($text, " \t*_");

        return mb_substr($text !== '' ? $text : $fallback, 0, 255);
    }
}
