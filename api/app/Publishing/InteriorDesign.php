<?php

namespace App\Publishing;

use Illuminate\Validation\ValidationException;

/**
 * The interior design controls on the Design & Layout screen (ADR-0040 —
 * Interior design controls): chapter and part openers, running heads and
 * folios, the printed and EPUB contents depth, and the style of each kind
 * of callout box.
 *
 * Stored in the book's `layout` beside trim, margins and type, as four
 * keyed groups and one string:
 *
 *   opener   style, label, custom, chapters_recto, parts_recto,
 *            indent_first, lead
 *   heads    verso, recto, style, size, align, openers
 *   folio    bottom-center | bottom-outside | top-outside | with-head
 *   toc      depth (print: 1 parts and chapters, 2 + H2, 3 + H3) and
 *            epub (the EPUB's navigation, same scale)
 *   callouts preset, and types: {note: {border, tint, icon, label}, …}
 *
 * and, with ADR-0041 (Mirrored margins, trims, typography and house
 * styles):
 *
 *   page     top, bottom, inside, outside (inches; null: the margin
 *            preset's, PageSetup) and unit (in | mm, how the screen shows
 *            them)
 *   text     paragraph (indent | block), indent and spacing (em), align
 *            (justify | left), hyphenate, widows, orphans
 *   headings h1, h2, h3, h4 (h4 also sets h5 and h6): each size (em),
 *            weight, italic, case, above and below (em), align, face
 *            (body | heading)
 *   heading_font  an InteriorFonts key for titles and headings, or null
 *            for the body face
 *
 * The defaults reproduce the interior as it was set before these controls
 * existed, so a book nobody touches prints the same. The one deliberate
 * exception is the English chapter label, which spelled numbers to Twenty
 * and then switched to digits ("Chapter Twenty", "Chapter 21"); it now
 * spells every number, or uses numerals throughout, as the author picks.
 *
 * Everything is refused that is not in the schema, so a client cannot store
 * something the renderers then have to guess at.
 */
class InteriorDesign
{
    /** Part of every print fingerprint: bumped when the same settings set differently. */
    public const VERSION = 3;

    public const OPENER_STYLES = [
        'classic' => 'Classic: label, title, short rule, centred',
        'numeral' => 'Large numeral above the title',
        'rule' => 'Rule above, ranged left',
        'split' => 'Number left, title right',
        'smallcaps' => 'Small-caps label and title, ranged left',
        'sink' => 'Deep drop from the top, ranged left',
    ];

    public const LABELS = ['auto', 'words', 'numerals', 'roman', 'none', 'custom'];

    public const HEAD_SOURCES = ['book', 'author', 'part', 'chapter', 'section', 'none'];

    public const HEAD_STYLES = ['classic', 'italic', 'smallcaps', 'plain'];

    /** Size steps for running heads and folios, as a factor of today's. */
    public const HEAD_SIZES = ['small' => 0.88, 'normal' => 1.0, 'large' => 1.15];

    public const HEAD_ALIGNS = ['center', 'outside'];

    public const FOLIOS = ['bottom-center', 'bottom-outside', 'top-outside', 'with-head'];

    /** The box types an author can choose in the editor, in the picker's order. */
    public const CALLOUT_TYPES = ['note', 'key', 'pitfall', 'method', 'tip', 'example', 'check', 'plain'];

    public const CALLOUT_NAMES = [
        'note' => 'Note', 'key' => 'Key point', 'pitfall' => 'Caution', 'method' => 'Method',
        'tip' => 'Tip', 'example' => 'Example', 'check' => 'Check yourself', 'plain' => 'Plain box',
    ];

    public const BORDERS = ['box', 'heavy-left', 'left', 'rules', 'dashed', 'none'];

    /** Grey tints, in percent: what a black-and-white press prints evenly. */
    public const TINTS = [0, 5, 10, 15, 20];

    /**
     * Box style presets, all black and white. Classic is the style boxes had
     * before they could be chosen, type for type.
     */
    public const CALLOUT_PRESETS = [
        'classic' => [
            'note' => ['border' => 'left', 'tint' => 0, 'icon' => false],
            'key' => ['border' => 'none', 'tint' => 10, 'icon' => true],
            'pitfall' => ['border' => 'heavy-left', 'tint' => 0, 'icon' => true],
            'method' => ['border' => 'box', 'tint' => 0, 'icon' => true],
            'tip' => ['border' => 'dashed', 'tint' => 0, 'icon' => true],
            'example' => ['border' => 'rules', 'tint' => 0, 'icon' => true],
            'check' => ['border' => 'box', 'tint' => 0, 'icon' => true],
            'plain' => ['border' => 'box', 'tint' => 0, 'icon' => false],
        ],
        // Quiet: a rule at the left, no tints, no glyphs.
        'minimal' => [
            'note' => ['border' => 'left', 'tint' => 0, 'icon' => false],
            'key' => ['border' => 'heavy-left', 'tint' => 0, 'icon' => false],
            'pitfall' => ['border' => 'heavy-left', 'tint' => 0, 'icon' => false],
            'method' => ['border' => 'left', 'tint' => 0, 'icon' => false],
            'tip' => ['border' => 'left', 'tint' => 0, 'icon' => false],
            'example' => ['border' => 'rules', 'tint' => 0, 'icon' => false],
            'check' => ['border' => 'left', 'tint' => 0, 'icon' => false],
            'plain' => ['border' => 'left', 'tint' => 0, 'icon' => false],
        ],
        // Every box ruled all round, the important ones tinted.
        'boxed' => [
            'note' => ['border' => 'box', 'tint' => 0, 'icon' => true],
            'key' => ['border' => 'box', 'tint' => 10, 'icon' => true],
            'pitfall' => ['border' => 'heavy-left', 'tint' => 5, 'icon' => true],
            'method' => ['border' => 'box', 'tint' => 0, 'icon' => true],
            'tip' => ['border' => 'dashed', 'tint' => 0, 'icon' => true],
            'example' => ['border' => 'box', 'tint' => 0, 'icon' => true],
            'check' => ['border' => 'box', 'tint' => 5, 'icon' => true],
            'plain' => ['border' => 'box', 'tint' => 0, 'icon' => false],
        ],
        // Tints instead of rules, light enough for any print service.
        'shaded' => [
            'note' => ['border' => 'none', 'tint' => 5, 'icon' => false],
            'key' => ['border' => 'none', 'tint' => 15, 'icon' => true],
            'pitfall' => ['border' => 'heavy-left', 'tint' => 10, 'icon' => true],
            'method' => ['border' => 'none', 'tint' => 10, 'icon' => true],
            'tip' => ['border' => 'none', 'tint' => 5, 'icon' => true],
            'example' => ['border' => 'rules', 'tint' => 5, 'icon' => true],
            'check' => ['border' => 'none', 'tint' => 10, 'icon' => true],
            'plain' => ['border' => 'none', 'tint' => 5, 'icon' => false],
        ],
    ];

    public const DEFAULTS = [
        'opener' => [
            'style' => 'classic',
            'label' => 'auto',
            'custom' => '',
            'chapters_recto' => false,
            'parts_recto' => true,
            'indent_first' => false,
            'lead' => true,
        ],
        'heads' => [
            'verso' => 'book',
            'recto' => 'chapter',
            'style' => 'classic',
            'size' => 'normal',
            'align' => 'center',
            'openers' => false,
        ],
        'folio' => 'bottom-center',
        'toc' => ['depth' => 1, 'epub' => 2],
        'callouts' => ['preset' => 'classic', 'types' => []],
        'page' => ['top' => null, 'bottom' => null, 'inside' => null, 'outside' => null, 'unit' => 'in'],
        // The paragraph and heading styles PrintBook::css() always set.
        'text' => ['paragraph' => 'indent', 'indent' => 1.35, 'spacing' => 0.6, 'align' => 'justify', 'hyphenate' => true, 'widows' => 2, 'orphans' => 2],
        'headings' => [
            'h1' => ['size' => 1.16, 'weight' => 'bold', 'italic' => false, 'case' => 'none', 'above' => 1.75, 'below' => 0.6, 'align' => 'left', 'face' => 'heading'],
            'h2' => ['size' => 1.16, 'weight' => 'bold', 'italic' => false, 'case' => 'none', 'above' => 1.75, 'below' => 0.6, 'align' => 'left', 'face' => 'heading'],
            'h3' => ['size' => 1.02, 'weight' => 'normal', 'italic' => true, 'case' => 'none', 'above' => 1.4, 'below' => 0.35, 'align' => 'left', 'face' => 'heading'],
            'h4' => ['size' => 0.8, 'weight' => 'normal', 'italic' => false, 'case' => 'upper', 'above' => 1.3, 'below' => 0.35, 'align' => 'left', 'face' => 'heading'],
        ],
        'heading_font' => null,
        // Figure and table numbering (ADR-00XX — Image and table placement):
        // null infers it from the captions, as Numbering always has.
        'numbering' => null,
    ];

    /** The figure and table numbering schemes a book can choose. */
    public const NUMBERING = ['continuous', 'chapter', 'part'];

    public const HEADING_LEVELS = ['h1', 'h2', 'h3', 'h4'];

    public const HEADING_CASES = ['none', 'upper', 'smallcaps'];

    public const ALIGNS = ['left', 'center', 'right'];

    /** Ranges for the numeric text and heading settings: [min, max]. */
    public const RANGES = [
        'page' => [0.25, 2.0],
        'indent' => [0.5, 3.0],
        'spacing' => [0.0, 2.0],
        'lines' => [1, 5],
        'size' => [0.7, 2.5],
        'above' => [0.0, 4.0],
        'below' => [0.0, 3.0],
    ];

    /** The words a box chosen in the editor is labelled with when it has no label of its own. */
    private const CALLOUT_WORDS = [
        'en' => ['note' => 'Note', 'key' => 'Key point', 'pitfall' => 'Caution', 'method' => 'Method', 'tip' => 'Tip', 'example' => 'Example', 'check' => 'Check yourself'],
        'fr' => ['note' => 'Remarque', 'key' => 'À retenir', 'pitfall' => 'Attention', 'method' => 'Méthode', 'tip' => 'Astuce', 'example' => 'Exemple', 'check' => 'Vérifiez-vous'],
        'ht' => ['note' => 'Remak', 'key' => 'Pou sonje', 'pitfall' => 'Atansyon', 'method' => 'Metòd', 'tip' => 'Konsèy', 'example' => 'Egzanp', 'check' => 'Tcheke tèt ou'],
        'es' => ['note' => 'Nota', 'key' => 'Para recordar', 'pitfall' => 'Cuidado', 'method' => 'Método', 'tip' => 'Consejo', 'example' => 'Ejemplo', 'check' => 'Compruébate'],
    ];

    /**
     * The stored design with every default filled in; a value that is no
     * longer valid falls back to its default rather than reaching a
     * renderer.
     *
     * @param  array<string, mixed>  $layout  the book's stored layout
     * @return array<string, mixed> the five design keys
     */
    public static function resolve(array $layout): array
    {
        $out = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $layout[$key] ?? null;

            if (is_array($default)) {
                $merged = $default;

                foreach (is_array($value) ? $value : [] as $sub => $v) {
                    if (array_key_exists($sub, $default) && self::valid($key, $sub, $v)) {
                        $merged[$sub] = self::cast($key, $sub, $v);
                    }
                }

                $out[$key] = $merged;
            } else {
                $out[$key] = self::valid($key, null, $value) ? $value : $default;
            }
        }

        return $out;
    }

    /**
     * Check a change to the design and merge it over the current values.
     * Groups may be sent in part ({"heads": {"recto": "section"}}).
     *
     * @param  array<string, mixed>  $changes  only the design keys
     * @param  array<string, mixed>  $current  resolve()d values
     * @return array<string, mixed> the changed groups, whole
     *
     * @throws ValidationException
     */
    public static function validate(array $changes, array $current): array
    {
        $errors = [];
        $out = [];

        foreach ($changes as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                $errors[$key] = "“{$key}” is not a design setting.";

                continue;
            }

            if (! is_array(self::DEFAULTS[$key])) {
                self::valid($key, null, $value) ? $out[$key] = $value : $errors[$key] = self::message($key, null);

                continue;
            }

            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                $errors[$key] = "“{$key}” must be a set of named values.";

                continue;
            }

            $group = $current[$key] ?? self::DEFAULTS[$key];

            foreach ($value as $sub => $v) {
                if (! array_key_exists($sub, self::DEFAULTS[$key])) {
                    $errors["{$key}.{$sub}"] = "“{$sub}” is not a {$key} setting.";
                } elseif (! self::valid($key, $sub, $v)) {
                    $errors["{$key}.{$sub}"] = self::message($key, $sub);
                } else {
                    // A heading level sent in part keeps its other values.
                    $group[$sub] = $key === 'headings'
                        ? array_merge($group[$sub], self::castHeading($sub, $v, false))
                        : self::cast($key, $sub, $v);
                }
            }

            $out[$key] = $group;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private static function valid(string $key, ?string $sub, mixed $v): bool
    {
        return match ("{$key}.{$sub}") {
            'opener.style' => is_string($v) && isset(self::OPENER_STYLES[$v]),
            'opener.label' => in_array($v, self::LABELS, true),
            // A word ("Lesson", "Leçon", "Chapit"), set before the number.
            // Null too: the request turns an emptied field into null.
            'opener.custom' => $v === null || (is_string($v) && mb_strlen($v) <= 30 && ! preg_match('/[<>{}]/', $v)),
            'opener.chapters_recto', 'opener.parts_recto', 'opener.indent_first', 'opener.lead', 'heads.openers' => is_bool($v),
            'heads.verso', 'heads.recto' => in_array($v, self::HEAD_SOURCES, true),
            'heads.style' => in_array($v, self::HEAD_STYLES, true),
            'heads.size' => is_string($v) && isset(self::HEAD_SIZES[$v]),
            'heads.align' => in_array($v, self::HEAD_ALIGNS, true),
            'folio.' => in_array($v, self::FOLIOS, true),
            'toc.depth', 'toc.epub' => is_int($v) || (is_string($v) && ctype_digit($v)) ? in_array((int) $v, [1, 2, 3], true) : false,
            'callouts.preset' => is_string($v) && isset(self::CALLOUT_PRESETS[$v]),
            'callouts.types' => self::validTypes($v),
            'page.top', 'page.bottom', 'page.inside', 'page.outside' => $v === null || self::number($v, self::RANGES['page']),
            'page.unit' => in_array($v, ['in', 'mm'], true),
            'text.paragraph' => in_array($v, ['indent', 'block'], true),
            'text.indent' => self::number($v, self::RANGES['indent']),
            'text.spacing' => self::number($v, self::RANGES['spacing']),
            'text.align' => in_array($v, ['justify', 'left'], true),
            'text.hyphenate' => is_bool($v),
            'text.widows', 'text.orphans' => is_int($v) && $v >= self::RANGES['lines'][0] && $v <= self::RANGES['lines'][1],
            'headings.h1', 'headings.h2', 'headings.h3', 'headings.h4' => self::validHeading($v),
            'heading_font.' => $v === null || (is_string($v) && isset(InteriorFonts::FAMILIES[$v])),
            'numbering.' => $v === null || in_array($v, self::NUMBERING, true),
            default => false,
        };
    }

    private static function number(mixed $v, array $range): bool
    {
        return (is_int($v) || is_float($v)) && $v >= $range[0] - 1e-9 && $v <= $range[1] + 1e-9;
    }

    /** One heading level's style, whole or in part. */
    private static function validHeading(mixed $style): bool
    {
        if (! is_array($style) || ($style !== [] && array_is_list($style))) {
            return false;
        }

        foreach ($style as $k => $v) {
            $ok = match ($k) {
                'size' => self::number($v, self::RANGES['size']),
                'above' => self::number($v, self::RANGES['above']),
                'below' => self::number($v, self::RANGES['below']),
                'weight' => in_array($v, ['normal', 'bold'], true),
                'italic' => is_bool($v),
                'case' => in_array($v, self::HEADING_CASES, true),
                'align' => in_array($v, self::ALIGNS, true),
                'face' => in_array($v, ['body', 'heading'], true),
                default => false,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function cast(string $key, ?string $sub, mixed $v): mixed
    {
        return match ("{$key}.{$sub}") {
            'toc.depth', 'toc.epub' => (int) $v,
            'opener.custom' => trim((string) $v),
            'callouts.types' => self::castTypes($v),
            'page.top', 'page.bottom', 'page.inside', 'page.outside' => $v === null ? null : round((float) $v, 4),
            'text.indent', 'text.spacing' => round((float) $v, 3),
            'headings.h1', 'headings.h2', 'headings.h3', 'headings.h4' => self::castHeading($sub, $v),
            default => $v,
        };
    }

    /** Per-type overrides: {tip: {border: 'box', tint: 5, icon: true, label: 'Astuce'}}. */
    private static function validTypes(mixed $types): bool
    {
        if (! is_array($types) || ($types !== [] && array_is_list($types))) {
            return false;
        }

        foreach ($types as $type => $style) {
            if (! in_array($type, self::CALLOUT_TYPES, true) || ! is_array($style)) {
                return false;
            }

            foreach ($style as $k => $v) {
                $ok = match ($k) {
                    'border' => in_array($v, self::BORDERS, true),
                    'tint' => is_int($v) && in_array($v, self::TINTS, true),
                    'icon' => is_bool($v),
                    // null: the box's own label, or the type's word.
                    'label' => $v === null || (is_string($v) && mb_strlen($v) <= 40 && ! preg_match('/[<>{}]/', $v)),
                    default => false,
                };

                if (! $ok) {
                    return false;
                }
            }
        }

        return true;
    }

    /** A level's style, numbers as floats; over its default unless $whole is false. */
    private static function castHeading(string $level, array $style, bool $whole = true): array
    {
        foreach (['size', 'above', 'below'] as $k) {
            if (isset($style[$k])) {
                $style[$k] = round((float) $style[$k], 3);
            }
        }

        return $whole ? array_merge(self::DEFAULTS['headings'][$level], $style) : $style;
    }

    private static function castTypes(array $types): array
    {
        return array_map(fn (array $style) => array_key_exists('label', $style) && is_string($style['label'])
            ? ['label' => trim($style['label'])] + $style
            : $style, $types);
    }

    private static function message(string $key, ?string $sub): string
    {
        return match ("{$key}.{$sub}") {
            'opener.style' => 'Choose one of the opener styles: '.implode(', ', array_keys(self::OPENER_STYLES)).'.',
            'opener.label' => 'The chapter label is one of: '.implode(', ', self::LABELS).'.',
            'opener.custom' => 'A custom label is a word or two, up to 30 characters.',
            'heads.verso', 'heads.recto' => 'A running head shows one of: '.implode(', ', self::HEAD_SOURCES).'.',
            'heads.style' => 'Running-head style is one of: '.implode(', ', self::HEAD_STYLES).'.',
            'heads.size' => 'Running-head size is small, normal or large.',
            'heads.align' => 'Running heads are centred or at the outside edge.',
            'folio.' => 'Page numbers go '.implode(', ', self::FOLIOS).'.',
            'toc.depth', 'toc.epub' => 'Contents depth is 1 (parts and chapters), 2 (and their sections) or 3 (and subsections).',
            'callouts.preset' => 'Box style preset is one of: '.implode(', ', array_keys(self::CALLOUT_PRESETS)).'.',
            'callouts.types' => 'Each box type takes a border ('.implode(', ', self::BORDERS).'), a tint ('.implode(', ', self::TINTS).'), an icon on or off, and a label.',
            'page.top', 'page.bottom', 'page.inside', 'page.outside' => 'A margin is between 0.25 and 2 inches (6.35–50.8 mm).',
            'page.unit' => 'Margins are shown in inches (in) or millimetres (mm).',
            'text.paragraph' => 'Paragraphs are indented (indent) or spaced (block).',
            'text.indent' => 'The first-line indent is between 0.5 and 3 em.',
            'text.spacing' => 'The space between block paragraphs is between 0 and 2 em.',
            'text.align' => 'Text is justified or ragged right (left).',
            'text.hyphenate' => 'Hyphenation is on or off.',
            'text.widows', 'text.orphans' => 'Keep between 1 and 5 lines together.',
            'headings.h1', 'headings.h2', 'headings.h3', 'headings.h4' => 'A heading style takes size (0.7–2.5 em), weight (normal or bold), italic, case ('.implode(', ', self::HEADING_CASES).'), above (0–4 em), below (0–3 em), align ('.implode(', ', self::ALIGNS).') and face (body or heading).',
            'heading_font.' => 'The heading face is one of: '.implode(', ', InteriorFonts::keys()).', or none for the body face.',
            'numbering.' => 'Figures and tables are numbered '.implode(', ', self::NUMBERING).', or automatically (none).',
            default => 'That value is not allowed.',
        };
    }

    // --- Openers -------------------------------------------------------------

    /**
     * An opener's label: "Chapter Four", "Chapitre 4", "Part II", "Leçon 4",
     * or null for none.
     *
     * auto keeps each language's convention: English spells chapter and
     * part numbers (every one of them, not just to Twenty), other languages
     * use numerals for chapters and roman numerals for parts. words spells
     * them in English and French and falls back to numerals elsewhere;
     * custom sets the author's word before a chapter's numeral, and leaves
     * parts as auto.
     *
     * @param  array<string, mixed>  $opener  resolve()d opener settings
     */
    public static function label(string $kind, int $number, string $language, array $opener, string $word): ?string
    {
        $lang = strtolower(substr($language, 0, 2));
        $mode = $opener['label'] ?? 'auto';

        if ($mode === 'none') {
            return null;
        }

        if ($mode === 'custom') {
            $custom = trim((string) ($opener['custom'] ?? ''));

            if ($kind === 'chapter') {
                return $custom === '' ? (string) $number : $custom.' '.$number;
            }

            $mode = 'auto';
        }

        $figure = match ($mode) {
            'words' => self::spell($number, $lang) ?? (string) $number,
            'numerals' => (string) $number,
            'roman' => self::roman($number),
            default => $lang === 'en'
                ? self::spell($number, 'en')
                : ($kind === 'part' ? self::roman($number) : (string) $number),
        };

        return $word.' '.$figure;
    }

    /** The figure a large-numeral opener sets: the label's number alone. */
    public static function figure(string $kind, int $number, array $opener): string
    {
        return match ($opener['label'] ?? 'auto') {
            'roman' => self::roman($number),
            default => $kind === 'part' && ($opener['label'] ?? 'auto') !== 'numerals' ? self::roman($number) : (string) $number,
        };
    }

    /** A number in words, capitalised ("Twenty-One", "Vingt et un"); null where the language is not spelled. */
    public static function spell(int $n, string $lang): ?string
    {
        if ($n < 1 || $n > 999) {
            return null;
        }

        $words = match ($lang) {
            'en' => self::english($n),
            'fr' => self::french($n),
            default => null,
        };

        return $words === null ? null : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }

    private static function english(int $n): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
            'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        if ($n >= 100) {
            $rest = $n % 100;

            return $ones[intdiv($n, 100)].' hundred'.($rest ? ' and '.self::english($rest) : '');
        }

        $word = $n < 20 ? $ones[$n] : $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '');

        // Title case, the way a label is set: "Twenty-One".
        return implode('-', array_map('ucfirst', explode('-', $word)));
    }

    private static function french(int $n): string
    {
        $ones = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze',
            'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
        $tens = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

        if ($n >= 100) {
            $hundreds = intdiv($n, 100);
            $rest = $n % 100;

            return ($hundreds > 1 ? $ones[$hundreds].' ' : '').'cent'.($hundreds > 1 && ! $rest ? 's' : '').($rest ? ' '.self::french($rest) : '');
        }

        if ($n < 20) {
            return $ones[$n];
        }

        $ten = intdiv($n, 10);
        $unit = $n % 10;

        // 70-79 and 90-99 count on from 60 and 80: soixante-douze, quatre-vingt-dix-sept.
        if ($ten === 7 || $ten === 9) {
            $unit += 10;
        }

        return match (true) {
            $unit === 0 => $tens[$ten].($ten === 8 ? 's' : ''),
            $unit === 1 && $ten < 8 => $tens[$ten].' et un',
            $unit === 11 && $ten === 7 => 'soixante et onze',
            default => $tens[$ten].'-'.$ones[$unit],
        };
    }

    public static function roman(int $n): string
    {
        $out = '';

        foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90,
            'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $r => $v) {
            while ($n >= $v) {
                $out .= $r;
                $n -= $v;
            }
        }

        return $out;
    }

    // --- Running heads and folios --------------------------------------------

    /**
     * How a running head is set on one side: the text it shows and the
     * type. Shared by PrintBook::decorate(), which draws it on the PDF, and
     * the previews, which draw it over the page in the same face.
     *
     * Classic is the head as it always was: the verso in spaced capitals at
     * 7.2 pt, the recto in italic at 8.5 pt, whatever each side shows.
     *
     * @param  array{book: string, author: string, part: ?string, chapter: ?string, section: ?string}  $texts
     * @return array{text: string, size: float, spacing: float, italic: bool, upper: bool, source: string}|null
     */
    public static function head(array $heads, bool $verso, array $texts): ?array
    {
        $source = $heads[$verso ? 'verso' : 'recto'];

        if ($source === 'none') {
            return null;
        }

        // A part or section the page is not in falls back to the chapter,
        // and an anonymous book's author to nothing.
        $text = match ($source) {
            'book' => $texts['book'],
            'author' => $texts['author'],
            'part' => $texts['part'] ?? $texts['chapter'],
            'section' => $texts['section'] ?? $texts['chapter'],
            // A page with no chapter of its own (the contents) shows nothing.
            default => $texts['chapter'],
        };

        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $caps = match ($heads['style']) {
            'classic' => $verso,
            'smallcaps' => true,
            default => false,
        };
        $factor = self::HEAD_SIZES[$heads['size']] ?? 1.0;

        return [
            'text' => $caps ? mb_strtoupper($text) : $text,
            'size' => round(($caps ? 7.2 : 8.5) * $factor, 2),
            'spacing' => $caps ? 1.4 : 0.2,
            'italic' => ! $caps && $heads['style'] !== 'plain',
            'upper' => $caps,
            'source' => $source,
        ];
    }

    /** The folio's type size, in points. */
    public static function folioSize(array $heads): float
    {
        return round(8.5 * (self::HEAD_SIZES[$heads['size']] ?? 1.0), 2);
    }

    // --- Callouts ------------------------------------------------------------

    /**
     * Every box type's resolved style: the preset's, with the book's
     * overrides over it, and the label (null: the box's own, else the
     * type's word).
     *
     * @return array<string, array{border: string, tint: int, icon: bool, label: ?string}>
     */
    public static function calloutStyles(array $callouts): array
    {
        $preset = self::CALLOUT_PRESETS[$callouts['preset'] ?? 'classic'] ?? self::CALLOUT_PRESETS['classic'];
        $out = [];

        foreach (self::CALLOUT_TYPES as $type) {
            $out[$type] = array_merge($preset[$type], ['label' => null], $callouts['types'][$type] ?? []);
        }

        return $out;
    }

    /** Whether the boxes are styled as they were before they could be chosen. */
    public static function classicCallouts(array $callouts): bool
    {
        return self::calloutStyles($callouts) === self::calloutStyles(self::DEFAULTS['callouts']);
    }

    /** The word a box of this type is labelled with when it has none of its own. */
    public static function calloutWord(string $type, string $language): ?string
    {
        $words = self::CALLOUT_WORDS[strtolower(substr($language, 0, 2))] ?? self::CALLOUT_WORDS['en'];

        return $words[$type] ?? null;
    }

    /**
     * The box styles as CSS rules, for one target:
     *
     *   print   PrintBook's stylesheet, in points, after its own rules
     *   epub    the EPUB's stylesheet, in pixels and ems
     *   editor  the manuscript editor, on blockquote[data-callout]
     *
     * Print and EPUB already carry the classic styles, so for a book still
     * on them nothing is added and the files stay byte for byte the same.
     * The editor gets every type, since it has no box styles of its own.
     */
    public static function calloutCss(array $callouts, string $target, string $language = 'en'): string
    {
        if ($target !== 'editor' && self::classicCallouts($callouts)) {
            return '';
        }

        [$thin, $heavy, $rule] = match ($target) {
            'print' => ['.5pt', '2.6pt', '1pt'],
            default => ['1px', '4px', '2px'],
        };
        $ink = $target === 'print' ? '#1a1a1a' : '#222222';
        $css = '';

        foreach (self::calloutStyles($callouts) as $type => $style) {
            $selector = $target === 'editor'
                ? '.ProseMirror blockquote[data-callout="'.$type.'"]'
                : ($target === 'epub' ? 'aside.callout-'.$type : '.callout.callout-'.$type);

            $border = match ($style['border']) {
                'box' => "border: {$thin} solid {$ink};",
                'heavy-left' => "border: {$thin} solid {$ink}; border-left: {$heavy} solid {$ink};",
                'left' => "border: 0; border-left: {$rule} solid #555555;",
                'rules' => "border: 0; border-top: {$thin} solid {$ink}; border-bottom: {$thin} solid {$ink};",
                'dashed' => "border: {$thin} dashed {$ink};",
                default => 'border: 0;',
            };
            $grey = (int) round(255 * (1 - $style['tint'] / 100));
            $tint = $style['tint'] > 0 ? sprintf('background-color: #%02x%02x%02x;', $grey, $grey, $grey) : 'background-color: transparent;';

            $css .= "{$selector} { {$border} {$tint} }\n";

            if ($target === 'editor') {
                $label = $style['label'] ?? self::calloutWord($type, $language);
                $glyph = $style['icon'] ? (Textbook::GLYPHS[$type] ?? Textbook::EXTRA_GLYPHS[$type] ?? '') : '';
                $before = trim($glyph.' '.($label !== null && $label !== '' ? $label : ''));

                $css .= "{$selector} { font-style: normal; padding: .5em .8em; margin: 1em 0; }\n";
                $css .= "{$selector}::before { content: ".self::cssString($before === '' ? '' : $before)
                    ."; display: block; font-size: .78em; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-bottom: .25em; color: #444; }\n";
            }
        }

        return $css;
    }

    private static function cssString(string $text): string
    {
        return '"'.addcslashes($text, '"\\').'"';
    }
}
