<?php

namespace App\Editorial\Style;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Normalizer;

/**
 * What a style guide says (ADR — Style guides and review options).
 *
 * One shape for a guide's version and for a book's own overrides. Every
 * field is optional and null means "the guide says nothing about it": an
 * empty guide adds nothing to a review, so a book without one is reviewed
 * exactly as before.
 *
 * Bounded everywhere. The guide goes to the model on every review of the
 * agents that read it, so its size is a cost the author sets, and an
 * unbounded list would be a way to make every review expensive.
 */
final class StyleRules
{
    public const LANGUAGES = ['en-US', 'en-GB', 'fr-FR', 'fr-CA', 'ht'];

    public const QUOTES = ['double', 'guillemets', 'single'];

    public const DASHES = ['em_closed', 'em_spaced', 'en_spaced'];

    public const HEADING_CASES = ['sentence', 'title', 'upper'];

    public const DATE_FORMATS = ['dmy', 'mdy', 'iso'];

    public const SPELL_OUT = [10, 100];

    public const MAX_TERMS = 200;

    public const MAX_DICTIONARY = 2000;

    public const MAX_NOTES = 2000;

    /** Every key, with "says nothing". */
    public const EMPTY = [
        'language' => null,
        'spelling' => [],
        'preferred_terms' => [],
        'banned' => [],
        'dictionary' => [],
        'numbers' => ['spell_out_below' => null, 'numerals_for_measurements' => null],
        'serial_comma' => null,
        'quotes' => null,
        'french_spacing' => null,
        'dashes' => null,
        'heading_case' => null,
        'date_format' => null,
        'notes' => null,
    ];

    /** How each choice reads, in the guide editor and in the data block. */
    public const LABELS = [
        'quotes' => ['double' => '“double” curly quotes', 'guillemets' => '« guillemets » with thin no-break spaces inside', 'single' => '‘single’ curly quotes'],
        'dashes' => ['em_closed' => 'em dash, no spaces (word—word)', 'em_spaced' => 'em dash with spaces (word — word)', 'en_spaced' => 'en dash with spaces (word – word)'],
        'heading_case' => ['sentence' => 'Sentence case', 'title' => 'Title Case', 'upper' => 'UPPER CASE'],
        'date_format' => ['dmy' => '29 October 2026', 'mdy' => 'October 29, 2026', 'iso' => '2026-10-29'],
    ];

    /** @return array<string, mixed> */
    public static function validationRules(): array
    {
        return [
            'language' => ['nullable', 'string', 'in:'.implode(',', self::LANGUAGES)],
            'spelling' => ['sometimes', 'array', 'max:30'],
            'spelling.*' => ['string', 'max:120'],
            'preferred_terms' => ['sometimes', 'array', 'max:'.self::MAX_TERMS],
            'preferred_terms.*.use' => ['required', 'string', 'max:80'],
            'preferred_terms.*.avoid' => ['required', 'string', 'max:80'],
            'preferred_terms.*.note' => ['nullable', 'string', 'max:200'],
            'banned' => ['sometimes', 'array', 'max:'.self::MAX_TERMS],
            'banned.*.phrase' => ['required', 'string', 'max:80'],
            'banned.*.note' => ['nullable', 'string', 'max:200'],
            'dictionary' => ['sometimes', 'array', 'max:'.self::MAX_DICTIONARY],
            'dictionary.*' => ['string', 'max:60'],
            'numbers' => ['sometimes', 'array'],
            'numbers.spell_out_below' => ['nullable', 'integer', 'in:'.implode(',', self::SPELL_OUT)],
            'numbers.numerals_for_measurements' => ['nullable', 'boolean'],
            'serial_comma' => ['nullable', 'boolean'],
            'quotes' => ['nullable', 'string', 'in:'.implode(',', self::QUOTES)],
            'french_spacing' => ['nullable', 'boolean'],
            'dashes' => ['nullable', 'string', 'in:'.implode(',', self::DASHES)],
            'heading_case' => ['nullable', 'string', 'in:'.implode(',', self::HEADING_CASES)],
            'date_format' => ['nullable', 'string', 'in:'.implode(',', self::DATE_FORMATS)],
            'notes' => ['nullable', 'string', 'max:'.self::MAX_NOTES],
        ];
    }

    /**
     * Validate and normalise input into the stored shape: unknown keys
     * dropped, lists trimmed, deduplicated and emptied of blanks.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $input = array_intersect_key($input, self::EMPTY);
        Validator::make($input, self::validationRules())->validate();

        return self::normalise($input);
    }

    /**
     * The stored shape, from anything that was valid once (a version saved
     * before a field existed reads with that field empty).
     *
     * @param  array<string, mixed>|null  $rules
     * @return array<string, mixed>
     */
    public static function normalise(?array $rules): array
    {
        $rules = array_merge(self::EMPTY, array_intersect_key($rules ?? [], self::EMPTY));

        $text = fn ($v, int $max) => ($v = trim(self::nfc((string) $v))) === '' ? null : mb_substr($v, 0, $max);

        $rules['spelling'] = self::unique(array_map(fn ($s) => $text($s, 120), (array) $rules['spelling']));
        $rules['dictionary'] = self::unique(array_map(fn ($s) => $text($s, 60), (array) $rules['dictionary']), caseless: true);

        $seen = [];
        $rules['preferred_terms'] = array_values(array_filter(array_map(function ($t) use ($text, &$seen) {
            $use = $text($t['use'] ?? '', 80);
            $avoid = $text($t['avoid'] ?? '', 80);
            $key = mb_strtolower((string) $avoid);

            if (! $use || ! $avoid || isset($seen[$key]) || mb_strtolower($use) === $key) {
                return null;
            }

            $seen[$key] = true;

            return ['use' => $use, 'avoid' => $avoid, 'note' => $text($t['note'] ?? '', 200)];
        }, (array) $rules['preferred_terms'])));

        $seen = [];
        $rules['banned'] = array_values(array_filter(array_map(function ($t) use ($text, &$seen) {
            $phrase = $text($t['phrase'] ?? '', 80);
            $key = mb_strtolower((string) $phrase);

            if (! $phrase || isset($seen[$key])) {
                return null;
            }

            $seen[$key] = true;

            return ['phrase' => $phrase, 'note' => $text($t['note'] ?? '', 200)];
        }, (array) $rules['banned'])));

        $numbers = (array) $rules['numbers'];
        $rules['numbers'] = [
            'spell_out_below' => isset($numbers['spell_out_below']) && in_array((int) $numbers['spell_out_below'], self::SPELL_OUT, true) ? (int) $numbers['spell_out_below'] : null,
            'numerals_for_measurements' => isset($numbers['numerals_for_measurements']) ? (bool) $numbers['numerals_for_measurements'] : null,
        ];

        foreach (['serial_comma', 'french_spacing'] as $key) {
            $rules[$key] = $rules[$key] === null ? null : (bool) $rules[$key];
        }

        foreach (['language' => self::LANGUAGES, 'quotes' => self::QUOTES, 'dashes' => self::DASHES, 'heading_case' => self::HEADING_CASES, 'date_format' => self::DATE_FORMATS] as $key => $allowed) {
            $rules[$key] = in_array($rules[$key], $allowed, true) ? $rules[$key] : null;
        }

        $rules['notes'] = $rules['notes'] === null ? null : $text($rules['notes'], self::MAX_NOTES);

        return $rules;
    }

    /**
     * The book's rules: the guide's, then the book's own values over them
     * where the book sets one; the dictionary is both lists.
     *
     * @param  array<string, mixed>|null  $guide
     * @param  array<string, mixed>|null  $book
     * @return array<string, mixed>
     */
    public static function merge(?array $guide, ?array $book): array
    {
        $guide = self::normalise($guide);
        $book = self::normalise($book);
        $out = $guide;

        foreach (self::EMPTY as $key => $empty) {
            if ($key === 'dictionary') {
                continue;
            }

            if ($key === 'numbers') {
                foreach ($book['numbers'] as $k => $v) {
                    if ($v !== null) {
                        $out['numbers'][$k] = $v;
                    }
                }

                continue;
            }

            if ($book[$key] !== null && $book[$key] !== []) {
                $out[$key] = $book[$key];
            }
        }

        $out['dictionary'] = self::unique(array_merge($guide['dictionary'], $book['dictionary']), caseless: true);

        return $out;
    }

    /** Does this say anything at all? */
    public static function isEmpty(array $rules): bool
    {
        return self::normalise($rules) === self::EMPTY;
    }

    /**
     * The guide as the agents receive it: only what it says, with each
     * choice spelled out, so the model does not have to know our keys.
     *
     * @return array<string, mixed>
     */
    public static function forPrompt(array $rules): array
    {
        $rules = self::normalise($rules);
        $out = [];

        if ($rules['language']) {
            $out['language_variety'] = $rules['language'];
        }

        if ($rules['spelling']) {
            $out['spelling_preferences'] = $rules['spelling'];
        }

        if ($rules['preferred_terms']) {
            $out['preferred_terms'] = array_map(fn ($t) => array_filter(['use' => $t['use'], 'instead_of' => $t['avoid'], 'note' => $t['note']]), $rules['preferred_terms']);
        }

        if ($rules['banned']) {
            $out['banned_words_and_phrases'] = array_map(fn ($t) => array_filter(['phrase' => $t['phrase'], 'note' => $t['note']]), $rules['banned']);
        }

        if ($rules['dictionary']) {
            $out['never_flag_these_words'] = $rules['dictionary'];
        }

        if ($rules['numbers']['spell_out_below'] !== null) {
            $out['numbers']['spell_out_below'] = $rules['numbers']['spell_out_below'];
        }

        if ($rules['numbers']['numerals_for_measurements'] !== null) {
            $out['numbers']['numerals_for_measurements'] = $rules['numbers']['numerals_for_measurements'];
        }

        if ($rules['serial_comma'] !== null) {
            $out['serial_comma'] = $rules['serial_comma'] ? 'use the serial (Oxford) comma' : 'no serial comma';
        }

        if ($rules['french_spacing'] !== null) {
            $out['french_spacing'] = $rules['french_spacing']
                ? 'a no-break space before ; : ! ? and inside « »'
                : 'no space before ; : ! ?';
        }

        foreach (['quotes' => 'quotation_marks', 'dashes' => 'dashes', 'heading_case' => 'heading_capitalisation', 'date_format' => 'date_format'] as $key => $name) {
            if ($rules[$key]) {
                $out[$name] = self::LABELS[$key][$rules[$key]];
            }
        }

        if ($rules['notes']) {
            $out['notes'] = $rules['notes'];
        }

        return $out;
    }

    public static function digest(array $rules): string
    {
        return sha1(json_encode(self::normalise($rules), JSON_UNESCAPED_UNICODE));
    }

    /** NFC, so "Kreyòl" typed two ways is one word. */
    public static function nfc(string $s): string
    {
        return class_exists(Normalizer::class) ? (Normalizer::normalize($s, Normalizer::FORM_C) ?: $s) : $s;
    }

    /** @param  array<int, ?string>  $items */
    private static function unique(array $items, bool $caseless = false): array
    {
        $out = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }

            $key = $caseless ? mb_strtolower($item) : $item;
            $out[$key] ??= $item;
        }

        return array_values($out);
    }
}
