<?php

namespace App\Publishing;

use Illuminate\Support\Str;

/**
 * The furniture of a textbook or an exam-prep book, read from plain
 * Markdown-shaped content (docs/MARKDOWN-CONVENTIONS.md).
 *
 * Nothing here is new syntax. Authors already write a box as a quotation
 * opening with a bold label ("> **Piège.** …"), an exam paper's header as
 * such a box, a score grid as a table with an empty "Mes points" column and
 * a checklist as "- [ ] …". The editor stores those as ordinary blockquotes,
 * tables and lists, so they survive every round trip; this class says what
 * they are, and the renderers (DocumentRenderer for EPUB and HTML, PrintBook
 * through it for print) set them as a textbook does: labelled boxes, an exam
 * banner with boxed directions, ruled write-in cells and printable boxes.
 * Everything stays black and white, for print-on-demand interiors.
 */
class Textbook
{
    /**
     * Box types by the words their label starts with, in English, French,
     * Kreyòl and Spanish; matched without case or accents, longest first.
     */
    private const CALLOUTS = [
        'exam' => ['epreuve', 'examen', 'sujet', 'exam', 'test', 'practice test', 'egzamen'],
        'directions' => ['consignes', 'consigne', 'instructions', 'directions', 'konsiy', 'instrucciones'],
        'pitfall' => ['piege', 'attention', 'erreur frequente', 'erreurs frequentes', 'pitfall', 'warning', 'caution',
            'watch out', 'common mistake', 'pyej', 'atansyon', 'trampa', 'cuidado', 'atencion'],
        'key' => ['a retenir', "l'essentiel", 'retenir', 'definition', 'theoreme', 'propriete', 'formule', 'regle', 'key point',
            'remember', 'theorem', 'rule', 'pou sonje', 'sa pou w konnen', 'definisyon', 'para recordar', 'definicion'],
        'method' => ['methode', 'demarche', 'method', 'how to', 'metod', 'metodo'],
        'tip' => ["astuce d'examen", 'astuce', 'conseil', "le jour de l'examen", 'exam tip', 'tip', 'strategy', 'konsey', 'astis', 'consejo'],
        'example' => ['exemple', 'example', 'worked example', 'egzanp', 'ejemplo'],
        'note' => ['remarque', 'note', 'nota', 'pour aller plus loin', 'rappel', 'remark', 'going further', 'remak', 'not'],
        'check' => ['verifiez-vous', 'je fais le point', 'testez-vous', 'check yourself', 'try on your own', 'reflect', 'tcheke', 'compruebate'],
    ];

    /** The glyph that marks each type in print, all in DejaVu Sans. */
    public const GLYPHS = ['pitfall' => '⚠', 'key' => '◆', 'method' => '✎', 'tip' => '★', 'example' => '▸', 'check' => '✔'];

    /** Column heads that are left for the reader to fill in. */
    private const WRITE_IN = ['mes points', 'mon score', 'ma note', 'points obtenus', 'note obtenue', 'mon temps', 'date', 'temps',
        'score', 'mes reponses', 'ma reponse', '✔', '✓', 'correct', 'my points', 'my score', 'my answer', 'my answers',
        'pwen mwen', 'pwen pa m', 'pwen pa m yo', 'not mwen', 'not pa m', 'mis puntos', 'mi puntuacion'];

    /** The book's words for an exam's furniture. */
    private const WORDS = [
        'en' => ['end' => 'End of test', 'continue' => 'Continue', 'answers' => 'Answer', 'question' => 'Question'],
        'fr' => ['end' => 'Fin de l’épreuve', 'continue' => 'Suite', 'answers' => 'Corrigé', 'question' => 'Sujet'],
        'ht' => ['end' => 'Fen egzamen an', 'continue' => 'Kontinye', 'answers' => 'Koreksyon', 'question' => 'Sijè'],
        'es' => ['end' => 'Fin del examen', 'continue' => 'Continúa', 'answers' => 'Solución', 'question' => 'Enunciado'],
        'de' => ['end' => 'Ende der Prüfung', 'continue' => 'Weiter', 'answers' => 'Lösung', 'question' => 'Aufgabe'],
        'pt' => ['end' => 'Fim da prova', 'continue' => 'Continua', 'answers' => 'Correção', 'question' => 'Enunciado'],
    ];

    /** @return array{end: string, continue: string, answers: string, question: string} */
    public static function words(string $language): array
    {
        return self::WORDS[strtolower(substr($language, 0, 2))] ?? self::WORDS['en'];
    }

    /** Lower case, no accents, curly apostrophes straightened: how labels are compared. */
    public static function fold(string $text): string
    {
        return trim(mb_strtolower(Str::ascii(str_replace(['’', 'ʼ'], "'", $text))));
    }

    /**
     * A box's type and label, for a blockquote that opens with a bold label
     * ending in a full stop or a colon ("**Piège.**", "**Méthode. Étudier
     * le signe.**"); null for a quotation.
     *
     * @param  array<string, mixed>  $node
     * @return array{type: string, label: string}|null
     */
    public static function callout(array $node): ?array
    {
        if (($node['type'] ?? '') !== 'blockquote') {
            return null;
        }

        $first = $node['content'][0] ?? null;
        $lead = is_array($first) && ($first['type'] ?? '') === 'paragraph' ? ($first['content'][0] ?? null) : null;

        if (! is_array($lead) || ($lead['type'] ?? '') !== 'text'
            || ! array_intersect(['bold', 'strong'], array_column($lead['marks'] ?? [], 'type'))) {
            return null;
        }

        $label = trim((string) ($lead['text'] ?? ''));

        if ($label === '' || mb_strlen($label) > 90 || ! preg_match('/[.:]$/u', $label)) {
            return null;
        }

        return ['type' => self::calloutType($label), 'label' => $label];
    }

    /** The type a label names, 'plain' when none of the known words opens it. */
    public static function calloutType(string $label): string
    {
        $folded = self::fold($label);
        $best = ['plain', 0];

        foreach (self::CALLOUTS as $type => $words) {
            foreach ($words as $word) {
                if (strlen($word) > $best[1] && preg_match('/^'.preg_quote($word, '/').'(?![a-z])/', $folded)) {
                    $best = [$type, strlen($word)];
                }
            }
        }

        // An exam header is "Épreuve." on its own, or with the paper's
        // name after it; "Exemple tiré de l'examen" is an example.
        return $best[0];
    }

    /**
     * The fields of an exam header box: its title (the text after the
     * label) and each "Name : value" item of its list.
     *
     * @param  array<string, mixed>  $node
     * @return array{title: string, fields: list<array{0: string, 1: string}>, rest: list<array<string, mixed>>}
     */
    public static function examHeader(array $node): array
    {
        $children = $node['content'] ?? [];
        $first = array_shift($children);
        $title = trim(ltrim(self::plain(array_slice($first['content'] ?? [], 1)), " \u{00A0}—–-:"));
        $fields = [];
        $rest = [];

        foreach ($children as $child) {
            if (in_array($child['type'] ?? '', ['bulletList', 'orderedList'], true)) {
                foreach ($child['content'] ?? [] as $item) {
                    $text = trim(self::plain($item['content'] ?? []));

                    if (preg_match('/^([^:]{1,40}?)\s*:\s*(.+)$/u', $text, $m)) {
                        $fields[] = [trim($m[1]), trim($m[2])];
                    } elseif ($text !== '') {
                        $fields[] = ['', $text];
                    }
                }

                continue;
            }

            $rest[] = $child;
        }

        return ['title' => $title, 'fields' => $fields, 'rest' => $rest];
    }

    /** Is this paragraph an explicit end of the paper ("Fin de l'épreuve.", "*Fin du sujet.*")? */
    public static function isExamEnd(mixed $node): bool
    {
        return is_array($node) && ($node['type'] ?? '') === 'paragraph'
            && (bool) preg_match("/^(fin de l'epreuve|fin du sujet|fin del examen|end of (the )?(test|exam|paper)|fen egzamen( an)?|fen sije a)\\b/",
                self::fold(self::plain($node['content'] ?? [])));
    }

    /** A heading that names the paper itself: "Le sujet", "L'épreuve", "The exam". */
    private const PAPER_HEADINGS = "/^(le sujet|l'epreuve|sujet|epreuve|le sujet officiel|the exam|the test|the paper|the question paper|sije a|egzamen an|el examen|el enunciado)$/";

    /** Headings inside a paper: its parts, sections and exercises. */
    private const PART_HEADINGS = '/^((premiere|deuxieme|troisieme|quatrieme|seconde|first|second|third)\s+)?(partie|part|pati|parte|teil|exercices?|exercises?|egzesis|ejercicios?|sections?|questions?|textes?|texts?|documents?|problemes?|problems?|pwoblem|reading|grammar|vocabulary|writing|comprehension|production|redaction|model text)\b|^[ivx]+\s*[.)\-–]|^[a-h]\s*[.)\-–]\s|^\d+\s*[.)\-–]\s/';

    /** Paragraphs that are an exam's directions. */
    private const DIRECTIONS = '/^(consignes?|n\.?\s?b\b|instructions?|directions?|konsiy|duree|dire\b|duration|remarques?\b|avertissement|important\b)/';

    /** Does folded text state how long the paper lasts ("Durée : 3 heures")? */
    private static function statesDuration(string $folded): bool
    {
        return (bool) preg_match('/\b(duree|dire|duration|duracion|duracao|dauer)\b[^:]{0,30}:\s*\d/', $folded);
    }

    /**
     * An exam paper's header paragraph: the paper's name and its terms
     * ("**Examen blanc n° 1 — Mathématiques**" and "**Séries : SMP — Durée :
     * 3 heures**" on two lines, or the ministry's lines with a duration
     * among them).
     */
    public static function isExamHeader(mixed $node, bool $requireDuration = true): bool
    {
        if (! is_array($node) || ($node['type'] ?? '') !== 'paragraph') {
            return false;
        }

        $children = $node['content'] ?? [];
        $text = self::plain($children);
        $folded = self::fold($text);

        if ($folded === '' || mb_strlen($text) > 600 || ($requireDuration && ! self::statesDuration($folded))
            || self::exerciseNumber($node) !== null) {
            return false;
        }

        $breaks = count(array_filter($children, fn ($c) => is_array($c) && ($c['type'] ?? '') === 'hardBreak'));
        $bold = self::isRunInHead(array_values(array_filter($children, fn ($c) => is_array($c) && ($c['type'] ?? '') !== 'hardBreak')), 600);
        $letters = preg_match_all('/\p{L}/u', $text);
        $capitals = preg_match_all('/\p{Lu}/u', $text);
        // A ministry's header is set in capitals: "BACCALAURÉAT … SÉRIE : SMP".
        $lead = (string) ($children[0]['text'] ?? '');
        $shouted = ($letters >= 20 && $capitals >= 0.7 * $letters)
            // …or opens with such a line in bold.
            || (array_intersect(['bold', 'strong'], array_column($children[0]['marks'] ?? [], 'type'))
                && preg_match_all('/\p{L}/u', $lead) >= 10 && preg_match_all('/\p{Lu}/u', $lead) >= 0.8 * preg_match_all('/\p{L}/u', $lead));

        // Fields ("Nom : … Matière : ANGLAIS Durée : 2 heures"), not a
        // sentence that happens to use colons.
        $fields = substr_count($text, ': ');
        $listed = $fields >= 3 && mb_strlen($text) / $fields < 45;

        return $breaks >= 2 || $listed || $shouted
            || ($bold && ($requireDuration || preg_match('/[:—–]/u', $text)));
    }

    /**
     * The exam papers among a section's top-level nodes.
     *
     * A paper starts at an "Épreuve." box, at a header paragraph that
     * states its duration, or under a heading that names it ("Le sujet",
     * "L'épreuve"). It ends at a paragraph that says so ("Fin du sujet."),
     * at the next heading as high as the one it sits under that is not one
     * of its own parts ("Partie B", "Exercice 2", "II. Grammar"), or at the
     * section's end; a paper fenced by thematic breaks ("---" just above its
     * header) ends at its last one.
     *
     * Each paper: its node range [start, end), the header and directions
     * nodes to box, rules to drop, and whether the node at `end` is the
     * author's own end line (which the end marker replaces).
     *
     * @param  array<int, mixed>  $nodes
     * @return list<array{start: int, end: int, closed: bool, header: list<int>, directions: list<int>, drop: list<int>, box: bool}>
     */
    public static function exams(array $nodes): array
    {
        $nodes = array_values($nodes);
        $count = count($nodes);
        $exams = [];
        $level = PHP_INT_MAX;
        $type = fn ($n) => is_array($n) ? ($n['type'] ?? '') : '';
        $text = fn ($n) => is_array($n) ? self::fold(self::plain($n['content'] ?? [])) : '';

        for ($i = 0; $i < $count; $i++) {
            $node = $nodes[$i];
            $box = false;
            $under = $level;

            if ($type($node) === 'heading') {
                if (preg_match(self::PAPER_HEADINGS, rtrim($text($node), ' .:'))) {
                    $first = $i + 1;
                    $under = (int) ($node['attrs']['level'] ?? 2);
                } else {
                    $level = (int) ($node['attrs']['level'] ?? 2);

                    continue;
                }
            } elseif ((self::callout($node)['type'] ?? null) === 'exam') {
                [$first, $box] = [$i, true];
            } elseif (self::isExamHeader($node)) {
                $first = $i;

                // Header lines just above the one naming the duration
                // ("EXAMEN DE 9E ANNÉE" over "Matière … Durée : 2 heures").
                while ($first > 0 && $i - $first < 3 && self::isExamHeader($nodes[$first - 1], false)) {
                    $first--;
                }
            } else {
                continue;
            }

            // A rule just above the header fences the paper.
            $fenced = $type($node) !== 'heading' && $first > 0 && $type($nodes[$first - 1]) === 'horizontalRule';
            $start = $fenced ? $first - 1 : $first;
            $end = $count;
            $closed = false;
            $lastRule = null;

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $nodes[$j];

                if (self::isExamEnd($next)) {
                    [$end, $closed] = [$j, true];

                    break;
                }

                if ((self::callout($next)['type'] ?? null) === 'exam' || ($j > $i + 3 && self::isExamHeader($next))) {
                    $end = $j;

                    break;
                }

                if ($type($next) === 'horizontalRule') {
                    $lastRule = $j;
                }

                // A fenced paper holds any heading but a chapter-level one
                // (a reading text's title is a heading too), and ends at its
                // last rule before that.
                $depth = (int) ($next['attrs']['level'] ?? 2);
                $ends = $type($next) === 'heading' && ! preg_match(self::PART_HEADINGS, $text($next))
                    && ($fenced ? $depth <= 2 : $depth <= $under);

                if ($ends) {
                    $end = $j;

                    break;
                }
            }

            if ($fenced && ! $closed && $lastRule !== null) {
                $end = $lastRule + 1;
            }

            // The header: the box, or the paragraphs that read as one at
            // the paper's head; then its directions.
            $header = [];
            $directions = [];
            $k = $first;

            if ($box) {
                $header[] = $k++;
            } else {
                // A figure or a note may come before the header lines.
                $lead = $k;
                while ($k < $end && $k < $lead + 2 && $type($nodes[$k]) === 'blockquote'
                    && in_array(self::callout($nodes[$k])['type'] ?? 'plain', ['plain', 'note'], true)) {
                    $k++;
                }

                while ($k < $end && count($header) < 4 && self::isExamHeader($nodes[$k], false)
                    && ! preg_match(self::DIRECTIONS, $text($nodes[$k]))) {
                    $header[] = $k++;
                }

                // Under "L'épreuve", a short first line is the paper's name.
                if ($header === [] && $type($node) === 'heading' && $type($nodes[$k] ?? null) === 'paragraph'
                    && mb_strlen(self::plain($nodes[$k]['content'] ?? [])) <= 300 && ! preg_match(self::DIRECTIONS, $text($nodes[$k]))) {
                    $header[] = $k++;
                }

                if ($header === []) {
                    $k = $lead;
                }
            }

            while ($k < $end && count($directions) < 5
                && ((self::callout($nodes[$k] ?? [])['type'] ?? null) === 'directions'
                    || ($type($nodes[$k]) === 'paragraph' && preg_match(self::DIRECTIONS, $text($nodes[$k]))))) {
                $directions[] = $k++;
            }

            // Rules that fenced or closed the paper: the banner and the end
            // marker do their work now.
            $drop = array_values(array_unique(array_filter(
                [$start, $end - 1],
                fn ($r) => $r >= $start && $r < $end && $type($nodes[$r]) === 'horizontalRule',
            )));

            $exams[] = ['start' => $start, 'end' => $end, 'closed' => $closed, 'header' => $header,
                'directions' => $directions, 'drop' => $drop, 'box' => $box];
            $i = max($i, $end - 1);
        }

        return $exams;
    }

    /**
     * The exercise a node opens in an exam or a corrigé, as its number:
     * a heading or a bold run-in label "Exercice 3", "Exercise 3",
     * "Egzèsis 3", "Ejercicio 3".
     */
    public static function exerciseNumber(array $node): ?string
    {
        $type = $node['type'] ?? '';
        $lead = $node['content'][0] ?? null;

        if ($type === 'heading') {
            $text = self::plain($node['content'] ?? []);
        } elseif ($type === 'paragraph' && is_array($lead) && array_intersect(['bold', 'strong'], array_column($lead['marks'] ?? [], 'type'))) {
            $text = (string) ($lead['text'] ?? '');
        } else {
            return null;
        }

        return preg_match('/^(?:(?:partie|part|pati)\s+[a-z0-9]+\s*[—–-]+\s*)?(?:exercices?|exercises?|egzesis|ejercicios?|aufgabe|exercicio)\s+(?:n[o°]\s*)?([a-z]?\d+[a-z]?)\b/u', self::fold($text), $m)
            ? $m[1]
            : null;
    }

    /** Does this heading open the answers part of a chapter ("Corrigé détaillé")? */
    public static function opensAnswers(array $node): bool
    {
        return ($node['type'] ?? '') === 'heading'
            && (bool) preg_match('/^(corrige|corriges|solutions?|answers?|answer key|koreksyon|soluciones|solucion)\b/', self::fold(self::plain($node['content'] ?? [])));
    }

    /**
     * Is this list a checklist: every item opens with "[ ]" or "[x]"?
     *
     * @param  array<string, mixed>  $node
     */
    public static function isChecklist(array $node): bool
    {
        if (($node['type'] ?? '') !== 'bulletList' || empty($node['content'])) {
            return false;
        }

        foreach ($node['content'] as $item) {
            $lead = $item['content'][0]['content'][0] ?? null;

            if (! is_array($lead) || ($lead['type'] ?? '') !== 'text' || ! preg_match('/^\[[ xX✓✔]\]\s/u', (string) ($lead['text'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A checklist item without its "[ ]", and whether it was ticked.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public static function checkItem(array $item): array
    {
        $text = (string) ($item['content'][0]['content'][0]['text'] ?? '');
        $ticked = (bool) preg_match('/^\[[xX✓✔]\]/u', $text);
        $rest = (string) preg_replace('/^\[[ xX✓✔]\]\s+/u', '', $text);

        if ($rest === '') {
            array_splice($item['content'][0]['content'], 0, 1);
        } else {
            $item['content'][0]['content'][0]['text'] = $rest;
        }

        return [$item, $ticked];
    }

    /**
     * Which columns of a table are left blank for the reader: a column whose
     * head is a write-in name ("Mes points", "Score", "✔"), or whose body
     * cells are all empty. Empty when the table is not a grid.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, true> column index => true
     */
    public static function writeInColumns(array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        $head = $rows[0]['content'] ?? [];

        if (count($rows) < 2 || ! $head || ($head[0]['type'] ?? '') !== 'tableHeader') {
            return [];
        }

        $columns = [];

        foreach ($head as $c => $cell) {
            $name = self::fold(self::plain($cell['content'] ?? []));
            $named = $name !== '' && (in_array($name, self::WRITE_IN, true)
                || (bool) preg_match('/^(mes points|mon score|ma note|score|my score|my points|points obtenus|note)\b.*\/\s*\d+$/', $name));
            $empty = true;

            foreach (array_slice($rows, 1) as $row) {
                if (trim(self::plain($row['content'][$c]['content'] ?? [])) !== '') {
                    $empty = false;

                    break;
                }
            }

            if ($named || ($empty && $name !== '')) {
                $columns[$c] = true;
            }
        }

        return $columns;
    }

    /** Is this row a total ("Total", "Sous-total A", "Subtotal")? */
    public static function isTotalRow(array $row): bool
    {
        return (bool) preg_match('/^(sous-total|sous total|subtotal|total|totaux|totals?)\b/', self::fold(self::plain($row['content'][0]['content'] ?? [])));
    }

    /**
     * A paragraph that is only a bold label ("**Partie B (60 points)**",
     * "**c) Covariance.**"), short enough to be a run-in head that should
     * not end a page alone.
     *
     * @param  array<int, mixed>  $children
     */
    public static function isRunInHead(array $children, int $longest = 140): bool
    {
        $text = '';

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child['type'] ?? '') === 'text') {
                $bold = (bool) array_intersect(['bold', 'strong'], array_column($child['marks'] ?? [], 'type'));

                if (! $bold && trim((string) $child['text']) !== '') {
                    return false;
                }

                $text .= (string) $child['text'];
            } elseif (($child['type'] ?? '') !== 'inlineMath') {
                return false;
            }
        }

        return trim($text) !== '' && mb_strlen($text) <= $longest;
    }

    /**
     * A heading's anchor: its cross-reference label, else one made from its
     * section and place, so a heading never labelled can still be linked.
     */
    public static function headingAnchor(array $node, int $sectionId, int $index): string
    {
        $label = $node['attrs']['label'] ?? null;

        return is_string($label) && preg_match('/^[a-z]+-[a-z0-9]+$/', $label) ? $label : 'h'.$sectionId.'-'.$index;
    }

    /** The anchor of an exercise's opener: a heading's own, else one made for it. */
    private static function exerciseAnchor(array $node, int $sectionId, int $index): string
    {
        return ($node['type'] ?? '') === 'heading' ? self::headingAnchor($node, $sectionId, $index) : 'ex'.$sectionId.'-'.$index;
    }

    /**
     * Headings that start with a section number ("1.3 L'essentiel du
     * cours"), by that number, for references written "voir 1.3"; a number
     * two headings share resolves to neither.
     *
     * @param  iterable<\App\Models\ManuscriptSection>  $sections
     * @return array<string, array{section_id: int, anchor: string}|false>
     */
    public static function numberedHeadings(iterable $sections): array
    {
        $found = [];

        foreach ($sections as $section) {
            foreach (array_values(Suggestions::published($section->content)['content'] ?? []) as $i => $node) {
                if (is_array($node) && ($node['type'] ?? '') === 'heading'
                    && preg_match('/^(\d+(?:\.\d+)+)\.?\s/u', trim(self::plain($node['content'] ?? [])), $m)) {
                    $found[$m[1]] = isset($found[$m[1]]) ? false : ['section_id' => $section->id, 'anchor' => self::headingAnchor($node, $section->id, $i)];
                }
            }
        }

        return $found;
    }

    /**
     * Exercises paired with their worked answers, keyed "section:index" of
     * each opener: the paper's "Exercice 3" and the corrigé's "Exercice 3".
     *
     * The answers are either the part of the same chapter after a heading
     * that opens them ("Corrigé détaillé"), or a chapter whose title names
     * the paper's ("Corrigé de l'examen blanc n° 1" for "Examen blanc n° 1
     * (SMP, SVT)", "Corrigé du test diagnostique" for "Test diagnostique").
     *
     * @param  iterable<\App\Models\ManuscriptSection>  $sections
     * @return array<string, array{id: string, kind: string, section_id: int, target: string}>
     */
    public static function exerciseLinks(iterable $sections): array
    {
        $papers = [];
        $answerSections = [];
        $links = [];

        $pair = function (int $qs, array $questions, int $as, array $answers) use (&$links): void {
            foreach ($questions as $n => [$qi, $qa]) {
                if (! isset($answers[$n])) {
                    continue;
                }

                [$ai, $aa] = $answers[$n];
                $links[$qs.':'.$qi] = ['id' => $qa, 'kind' => 'question', 'section_id' => $as, 'target' => $aa];
                $links[$as.':'.$ai] = ['id' => $aa, 'kind' => 'answer', 'section_id' => $qs, 'target' => $qa];
            }
        };

        $collect = function (array $nodes, int $sid, int $from, int $to): array {
            $found = [];

            for ($i = $from; $i < $to; $i++) {
                if (is_array($nodes[$i] ?? null) && ($n = self::exerciseNumber($nodes[$i])) !== null) {
                    $found[$n] ??= [$i, self::exerciseAnchor($nodes[$i], $sid, $i)];
                }
            }

            return $found;
        };

        $bare = fn (string $title) => trim((string) preg_replace("/^(l'|le |la |les |the )/", '', $title));

        foreach ($sections as $section) {
            $nodes = array_values(Suggestions::published($section->content)['content'] ?? []);
            $exams = self::exams($nodes);
            $title = self::fold($section->title);

            if ($exams !== []) {
                $papers[] = [$section->id, $bare($title), $collect($nodes, $section->id, $exams[0]['start'], $exams[0]['end'])];

                // Answers further down the same chapter.
                $last = end($exams);

                for ($i = $last['end']; $i < count($nodes); $i++) {
                    if (is_array($nodes[$i]) && self::opensAnswers($nodes[$i])) {
                        $pair($section->id, $collect($nodes, $section->id, $last['start'], $last['end']),
                            $section->id, $collect($nodes, $section->id, $i, count($nodes)));

                        break;
                    }
                }
            }

            if (preg_match("/^(?:corriges?|koreksyon|answers?(?: key)?|solutions?|solucion(?:es)?)\\s+(?:to |of |for |de l'|de la |du |des |de |d'|pou )?(.+)$/", $title, $m)) {
                $answerSections[] = [$section->id, $bare($m[1]), $collect($nodes, $section->id, 0, count($nodes))];
            }
        }

        foreach ($answerSections as [$as, $name, $answers]) {
            foreach ($papers as [$qs, $title, $questions]) {
                if ($name !== '' && $qs !== $as && str_starts_with($title, $name)) {
                    $pair($qs, $questions, $as, $answers);

                    break;
                }
            }
        }

        return $links;
    }
    /** @param array<int, mixed> $nodes */
    public static function plain(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $out .= match ($node['type'] ?? '') {
                'text' => (string) ($node['text'] ?? ''),
                'inlineMath' => '$'.($node['attrs']['latex'] ?? '').'$',
                'hardBreak' => ' ',
                default => self::plain($node['content'] ?? []),
            };

            if (in_array($node['type'] ?? '', ['paragraph', 'heading', 'listItem'], true)) {
                $out .= ' ';
            }
        }

        return $out;
    }
}
