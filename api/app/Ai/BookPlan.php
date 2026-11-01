<?php

namespace App\Ai;

use App\Models\Book;
use App\Publishing\Exporter;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A book's plan: its shape, its checks, and what is sent to the writer
 * (ADR — AI writes the book).
 *
 * The planner's answer and the author's edits both pass through
 * normalise(), so what is stored is always the same shape: bounded
 * strings, at most `writer.max_chapters` chapters, a stable key on every
 * section ("fm1", "c3", "bm1") that ties a binder section to its entry,
 * and words within bounds.
 */
final class BookPlan
{
    /** @return array<string, mixed> the structured-output schema for the planner */
    public static function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $section = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
                'purpose' => ['type' => 'string'],
                'key_points' => $strings,
                'words' => ['type' => 'integer'],
            ],
            'required' => ['title', 'purpose', 'key_points', 'words'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title_options' => $strings,
                'subtitle' => ['type' => 'string'],
                'reader' => ['type' => 'string'],
                'promise' => ['type' => 'string'],
                'voice' => ['type' => 'string'],
                'style_sheet' => $strings,
                'accuracy_rules' => $strings,
                'recurring_elements' => $strings,
                'fiction' => ['type' => 'boolean'],
                'characters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'role' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'role', 'description'],
                    ],
                ],
                'setting' => ['type' => 'string'],
                'front_matter' => ['type' => 'array', 'items' => $section],
                'parts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'chapters' => ['type' => 'array', 'items' => $section],
                        ],
                        'required' => ['title', 'chapters'],
                    ],
                ],
                'back_matter' => ['type' => 'array', 'items' => $section],
            ],
            'required' => [
                'title_options', 'subtitle', 'reader', 'promise', 'voice', 'style_sheet',
                'accuracy_rules', 'recurring_elements', 'fiction', 'characters', 'setting',
                'front_matter', 'parts', 'back_matter',
            ],
        ];
    }

    /**
     * The stored shape, from the planner's answer or the author's edits.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalise(array $raw, string $language): array
    {
        $max = (int) config('writer.max_chapters', 40);
        $count = 0;
        $n = ['fm' => 0, 'c' => 0, 'bm' => 0];

        $section = function (array $s, string $prefix) use (&$n): array {
            $key = is_string($s['key'] ?? null) && preg_match('/^(fm|c|bm)\d{1,3}$/', $s['key']) ? $s['key'] : null;
            $n[$prefix]++;

            return [
                'key' => $key ?? $prefix.$n[$prefix],
                'title' => self::str($s['title'] ?? '', 255) ?: 'Untitled',
                'purpose' => self::str($s['purpose'] ?? '', 1000),
                'key_points' => self::list($s['key_points'] ?? [], 12, 400),
                'words' => max(0, min((int) config('writer.max_chapter_words', 8000), (int) ($s['words'] ?? 0))),
            ];
        };

        $parts = [];

        foreach (array_values((array) ($raw['parts'] ?? [])) as $part) {
            $chapters = [];

            foreach (array_values((array) ($part['chapters'] ?? [])) as $chapter) {
                if ($count >= $max || ! is_array($chapter)) {
                    break;
                }

                $item = $section($chapter, 'c');
                $item['words'] = $item['words'] ?: 2000;
                $chapters[] = $item;
                $count++;
            }

            if ($chapters !== []) {
                $parts[] = ['title' => self::str($part['title'] ?? '', 255), 'chapters' => $chapters];
            }
        }

        // Keys must stay unique even when the author's edits reuse one.
        $seen = [];
        $fix = function (array $item) use (&$seen, &$n) {
            if (isset($seen[$item['key']])) {
                $prefix = preg_replace('/\d+$/', '', $item['key']);
                do {
                    $n[$prefix]++;
                    $item['key'] = $prefix.$n[$prefix];
                } while (isset($seen[$item['key']]));
            }
            $seen[$item['key']] = true;

            return $item;
        };

        $front = array_map($fix, array_map(fn ($s) => $section((array) $s, 'fm'), array_slice(array_values((array) ($raw['front_matter'] ?? [])), 0, 4)));
        $parts = array_map(fn ($p) => ['title' => $p['title'], 'chapters' => array_map($fix, $p['chapters'])], $parts);
        $back = array_map($fix, array_map(fn ($s) => $section((array) $s, 'bm'), array_slice(array_values((array) ($raw['back_matter'] ?? [])), 0, 4)));

        return [
            'language' => BookPrompts::language($raw['language'] ?? $language),
            'title_options' => self::list($raw['title_options'] ?? [], 5, 255),
            'title' => self::str($raw['title'] ?? (($raw['title_options'] ?? [])[0] ?? ''), 255),
            'subtitle' => self::str($raw['subtitle'] ?? '', 255),
            'reader' => self::str($raw['reader'] ?? '', 1000),
            'promise' => self::str($raw['promise'] ?? '', 1000),
            'voice' => self::str($raw['voice'] ?? '', 1000),
            'style_sheet' => self::list($raw['style_sheet'] ?? [], 20, 400),
            'accuracy_rules' => self::list($raw['accuracy_rules'] ?? [], 12, 400),
            'recurring_elements' => self::list($raw['recurring_elements'] ?? [], 8, 400),
            'fiction' => (bool) ($raw['fiction'] ?? false),
            'characters' => array_values(array_filter(array_map(fn ($c) => is_array($c) ? [
                'name' => self::str($c['name'] ?? '', 120),
                'role' => self::str($c['role'] ?? '', 200),
                'description' => self::str($c['description'] ?? '', 500),
            ] : null, array_slice((array) ($raw['characters'] ?? []), 0, 20)), fn ($c) => $c && $c['name'] !== '')),
            'setting' => self::str($raw['setting'] ?? '', 1000),
            'front_matter' => $front,
            'parts' => $parts,
            'back_matter' => $back,
        ];
    }

    /**
     * Every section of the plan in book order, with its binder type.
     *
     * @return list<array<string, mixed>>
     */
    public static function sections(array $plan): array
    {
        $out = [];

        foreach ($plan['front_matter'] ?? [] as $s) {
            $out[] = $s + ['type' => 'front_matter', 'part' => null];
        }

        foreach ($plan['parts'] ?? [] as $i => $part) {
            foreach ($part['chapters'] as $c) {
                $out[] = $c + ['type' => 'chapter', 'part' => $i];
            }
        }

        foreach ($plan['back_matter'] ?? [] as $s) {
            $out[] = $s + ['type' => 'back_matter', 'part' => null];
        }

        return $out;
    }

    public static function totalWords(array $plan): int
    {
        return array_sum(array_column(self::sections($plan), 'words'));
    }

    /** One entry by key, or null. */
    public static function find(array $plan, ?string $key): ?array
    {
        return collect(self::sections($plan))->firstWhere('key', $key);
    }

    /**
     * A plan for a book that has none, from what the book already says: so
     * "Write this chapter" works in the studio without planning first.
     *
     * @return array<string, mixed>
     */
    public static function fromBook(Book $book): array
    {
        $chapters = $book->sections()->whereIn('type', ['chapter'])->orderBy('position')->get();

        return self::normalise([
            'title' => $book->title,
            'subtitle' => (string) $book->subtitle,
            'reader' => (string) $book->audience,
            'promise' => (string) $book->promise,
            'voice' => '',
            'parts' => [['title' => '', 'chapters' => $chapters->map(fn ($c) => [
                'key' => $c->plan_key,
                'title' => $c->title,
                'purpose' => (string) $c->subtitle,
                'words' => (int) round(max(1000, min(5000, ((int) $book->target_words ?: 30000) / max(1, $chapters->count())))),
            ])->all()]],
        ], Exporter::languageCode($book->language));
    }

    /**
     * What the writer is sent: the plan without its bookkeeping, plus the
     * book's own facts. Deterministic (same plan, same bytes), so the
     * prompt cache holds across chapters.
     *
     * @return array<string, mixed>
     */
    public static function forPrompt(Book $book, array $plan): array
    {
        $strip = fn (array $s) => Arr::only($s, ['title', 'purpose', 'key_points', 'words']);

        return array_filter([
            'title' => ($plan['title'] ?? '') ?: $book->title,
            'subtitle' => ($plan['subtitle'] ?? '') ?: $book->subtitle,
            'language' => BookPrompts::LANGUAGES[BookPrompts::language($plan['language'] ?? 'en')][0],
            'kind_of_book' => $book->book_type ?: $book->genre,
            'reader' => ($plan['reader'] ?? '') ?: $book->audience,
            'promise' => ($plan['promise'] ?? '') ?: $book->promise,
            'premise' => $book->premise,
            'voice' => $plan['voice'] ?? null,
            'style_sheet' => $plan['style_sheet'] ?? [],
            'accuracy_rules' => $plan['accuracy_rules'] ?? [],
            'recurring_elements' => $plan['recurring_elements'] ?? [],
            'characters' => $plan['characters'] ?? [],
            'setting' => $plan['setting'] ?? null,
            'front_matter' => array_map($strip, $plan['front_matter'] ?? []),
            'parts' => array_map(fn ($p) => ['title' => $p['title'], 'chapters' => array_map($strip, $p['chapters'])], $plan['parts'] ?? []),
            'back_matter' => array_map($strip, $plan['back_matter'] ?? []),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private static function str(mixed $value, int $max): string
    {
        return is_scalar($value) ? Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''), $max, '') : '';
    }

    /** @return list<string> */
    private static function list(mixed $values, int $count, int $max): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => self::str($v, $max),
            array_slice(is_array($values) ? array_values($values) : [], 0, $count),
        ), fn ($v) => $v !== ''));
    }
}
