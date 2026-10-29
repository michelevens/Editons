<?php

namespace App\Editorial\Style;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\StyleGuide;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Validation\ValidationException;

/**
 * The Écrivain Prépa house style guide, created from the console by staff
 * (ADR — Style guides and review options). Nothing is seeded: the guide
 * exists only once the owner creates it, with a reason, audited.
 *
 * Its dictionary is read from the house account's own books: the bold
 * terms of their glossaries (Leksik, Lexique, Glossaire), from the Kreyòl
 * books, so the Copy Editor never flags "detèminan" or "chan leksikal" as
 * misspelt. Read only — the books are not touched — and the preview shows
 * every word before anything is created.
 */
final class HouseGuideTemplate
{
    public const NAME = 'Écrivain Prépa — style maison';

    /** Glossary chapters, in French, Kreyòl and English. */
    private const GLOSSARY = '/\b(leksik|lexique|glossaire|glossary)\b/iu';

    /**
     * The rules the guide would have, with the words found and where.
     *
     * @return array{name: string, rules: array<string, mixed>, sources: list<array{book: int, title: ?string, section: string, words: int}>}
     */
    public function preview(User $house): array
    {
        [$words, $sources] = $this->harvest($house);

        $rules = StyleRules::normalise([
            'language' => 'fr-FR',
            'spelling' => [
                'Kreyòl: graphie officielle (IPN 1979), accents graves (ò, è, à) toujours écrits',
                'French: rectified spelling (1990) accepted but not required; be consistent within a book',
                'English chapters (Anglais books): US spelling',
            ],
            // Proposals for staff to edit, not house law: each is a choice
            // the series already makes in its books.
            'preferred_terms' => [
                ['use' => 'Kreyòl', 'avoid' => 'Créole', 'note' => 'The language’s own name, in Kreyòl and French text.'],
                ['use' => '9e AF', 'avoid' => '9ème année', 'note' => 'The series’ name for the level.'],
            ],
            'banned' => [
                ['phrase' => 'approuvé par le MENFP', 'note' => 'The series is independent: no claim of MENFP endorsement.'],
                ['phrase' => 'réussite garantie', 'note' => 'No promise of exam results.'],
            ],
            'dictionary' => array_slice(array_merge(['Écrivain Prépa', 'Kreyòl', 'MENFP', 'NS4', 'AF'], $words), 0, StyleRules::MAX_DICTIONARY),
            'numbers' => ['spell_out_below' => 10, 'numerals_for_measurements' => true],
            'serial_comma' => false,
            'quotes' => 'guillemets',
            'french_spacing' => true,
            'dashes' => 'en_spaced',
            'heading_case' => 'sentence',
            'date_format' => 'dmy',
            'notes' => 'Exam-prep books for Haitian students (9e AF and Bac NS4). Anonymous series: never add an author name. Official exam papers are transcribed as published, errors included and marked [sic]; do not correct them. Address the student directly and plainly (tu in French, ou in Kreyòl).',
        ]);

        return ['name' => self::NAME, 'rules' => $rules, 'sources' => $sources];
    }

    /** Create the guide on the house account, audited. */
    public function create(User $house, User $actor, string $reason): StyleGuide
    {
        if (! $house->isHouse()) {
            throw ValidationException::withMessages(['house' => 'That is not a house account.']);
        }

        if (StyleGuide::where('user_id', $house->id)->where('name', self::NAME)->exists()) {
            throw ValidationException::withMessages(['house' => 'This house already has the “'.self::NAME.'” guide. Edit it from one of its books.']);
        }

        $preview = $this->preview($house);

        return AdminAudit::transaction(function () use ($house, $actor, $reason, $preview) {
            $guide = app(StyleGuides::class)->create($house, $actor, $preview['name'], $preview['rules']);

            AdminAudit::record('house.style_guide_created', $house, null, [
                'guide' => $guide->id,
                'name' => $guide->name,
                'dictionary' => count($preview['rules']['dictionary']),
                'sources' => $preview['sources'],
            ], $reason, $actor);

            return $guide;
        });
    }

    /**
     * The bold terms of the Kreyòl books' glossaries.
     *
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    private function harvest(User $house): array
    {
        $words = [];
        $sources = [];

        $books = Book::where('user_id', $house->id)->get(['id', 'title', 'language']);

        foreach ($books as $book) {
            $sections = ManuscriptSection::where('book_id', $book->id)->get(['id', 'title', 'content'])
                ->filter(fn ($s) => preg_match(self::GLOSSARY, (string) $s->title));

            foreach ($sections as $section) {
                // Only the Kreyòl books' terms: French and English glossary
                // words are ordinary dictionary words already.
                if (! $this->kreyol($book, $section)) {
                    continue;
                }

                $found = $this->bold($section->content ?? []);
                $sources[] = ['book' => $book->id, 'title' => $book->title, 'section' => $section->title, 'words' => count($found)];
                array_push($words, ...$found);
            }
        }

        $unique = [];

        foreach ($words as $w) {
            $unique[mb_strtolower($w)] ??= $w;
        }

        ksort($unique);

        return [array_values($unique), $sources];
    }

    private function kreyol(Book $book, ManuscriptSection $section): bool
    {
        return in_array(strtolower((string) $book->language), ['ht', 'hat', 'kreyol', 'kreyòl', 'creole'], true)
            || preg_match('/\bleksik\b/iu', (string) $section->title);
    }

    /**
     * Bold runs that look like a term: one to four words, letters only,
     * three letters or more.
     *
     * @return list<string>
     */
    private function bold(array $node): array
    {
        $out = [];

        if (($node['type'] ?? null) === 'text' && collect($node['marks'] ?? [])->contains(fn ($m) => ($m['type'] ?? null) === 'bold')) {
            $term = trim(preg_replace('/\s+/u', ' ', (string) ($node['text'] ?? '')), " \t\n.,;:!?()—–-");

            // Three letters at least: the glossaries bold single letters
            // and particles in their examples (a, k, l).
            if (mb_strlen($term) >= 3 && mb_strlen($term) <= 60 && preg_match('/^[\p{L}\'’ ]+$/u', $term) && count(explode(' ', $term)) <= 4) {
                $out[] = StyleRules::nfc($term);
            }
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                array_push($out, ...$this->bold($child));
            }
        }

        return $out;
    }
}
