<?php

namespace App\Platform\Trust;

use App\Journey\BookType;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Publishing\BookPart;
use App\Research\Bibliography;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lists the owner can edit that used to be constants in controllers.
 *
 * Only lists whose values are stored as plain text and checked as plain
 * text are editable: the genres and languages offered on a book's
 * settings, the languages offered for a translation edition, and the store
 * categories on the Publish screen. Removing an entry never changes a book
 * that already uses it — it only stops being offered — and the screens
 * still show a book's current value even when the list no longer has it.
 *
 * Citation styles are code (App\Research\Bibliography formats each one),
 * so the owner chooses which are *shown*, never adds one. Book types and
 * book parts are read-only here: types drive templates, agents and
 * questions (config/booktypes.php), part names drive rendering in six
 * languages (App\Publishing\BookPart), and neither has an override the
 * code reads safely. Changing them is a deploy.
 *
 * The defaults below are the constants as they were, so with no row in
 * master_lists behaviour is exactly what it was.
 */
final class MasterData
{
    public const LISTS = [
        'genres' => [
            'label' => 'Genres',
            'where' => 'Book settings → Genre',
            'default' => ['Nonfiction', 'Memoir', 'Textbook', 'Technical', 'Fiction'],
            'max' => 120,
        ],
        'languages' => [
            'label' => 'Book languages',
            'where' => 'Book settings → Language',
            'default' => ['English (US)', 'English (UK)', 'Spanish', 'French', 'Haitian Creole', 'German', 'Portuguese'],
            'max' => 60,
        ],
        'translation_languages' => [
            'label' => 'Translation languages',
            'where' => 'Editions → New translation',
            'default' => ['Spanish', 'French', 'Portuguese', 'German', 'Italian', 'Dutch'],
            'max' => 50,
        ],
        'store_categories' => [
            'label' => 'Store categories (BISAC-style)',
            'where' => 'Export & Publish → Category',
            'default' => [
                'Business & Economics / Small Business',
                'Business & Economics / Entrepreneurship',
                'Medical / Practice Management',
                'Medical / Nursing / Practice Management',
                'Self-Help / Personal Growth',
                'Education / Professional Development',
                'Fiction / Literary',
                'Biography & Autobiography / Personal Memoirs',
            ],
            'max' => 255,
        ],
        // A subset of Bibliography::STYLES, never more.
        'citation_styles' => [
            'label' => 'Citation styles shown',
            'where' => 'Book settings and References → Style',
            'default' => Bibliography::STYLES,
            'max' => 40,
        ],
    ];

    private const CACHE_KEY = 'platform.master-lists';

    /** @return list<string> the list as the app should offer it now */
    public static function get(string $key): array
    {
        $meta = self::LISTS[$key] ?? throw new \InvalidArgumentException("Unknown list [{$key}].");

        return self::stored()[$key] ?? $meta['default'];
    }

    /** The citation styles to offer, as Bibliography::options() rows. */
    public static function citationOptions(?string $current = null): array
    {
        $shown = self::get('citation_styles');

        return array_values(array_filter(
            Bibliography::options(),
            // The book's own style stays selectable even when hidden.
            fn (array $o) => in_array($o['value'], $shown, true) || $o['value'] === $current,
        ));
    }

    /** A list, with a value the book already has kept in it. */
    public static function with(string $key, ?string $current): array
    {
        $list = self::get($key);

        return filled($current) && ! in_array($current, $list, true) ? [...$list, $current] : $list;
    }

    /**
     * Replace a list, audited.
     *
     * @param  list<string>  $items
     */
    public static function set(string $key, array $items, string $reason, User $actor): void
    {
        $meta = self::LISTS[$key] ?? throw ValidationException::withMessages(['key' => 'Unknown list.']);

        $clean = [];

        foreach ($items as $item) {
            $item = trim((string) $item);

            if ($item === '') {
                continue;
            }

            if (mb_strlen($item) > $meta['max']) {
                throw ValidationException::withMessages(['items' => "“{$item}” is longer than {$meta['max']} characters."]);
            }

            if (! in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['items' => 'Keep at least one entry.']);
        }

        if (count($clean) > 200) {
            throw ValidationException::withMessages(['items' => 'At most 200 entries.']);
        }

        if ($key === 'citation_styles') {
            $unknown = array_diff($clean, Bibliography::STYLES);

            if ($unknown) {
                throw ValidationException::withMessages(['items' => 'Only styles the app can format: '.implode(', ', Bibliography::STYLES).'.']);
            }

            // In the order the formatter lists them.
            $clean = array_values(array_intersect(Bibliography::STYLES, $clean));
        }

        $before = self::get($key);

        if ($before === $clean) {
            return;
        }

        AdminAudit::transaction(function () use ($key, $clean, $before, $reason, $actor) {
            DB::table('master_lists')->updateOrInsert(
                ['key' => $key],
                ['items' => json_encode($clean), 'updated_by' => $actor->id, 'updated_at' => now()],
            );

            AdminAudit::record('master_data.updated', null,
                ['list' => $key, 'removed' => array_values(array_diff($before, $clean))],
                ['list' => $key, 'added' => array_values(array_diff($clean, $before)), 'items' => $clean],
                $reason, $actor);
        });

        Cache::forget(self::CACHE_KEY);
    }

    /** Back to the constants: the stored row goes. */
    public static function reset(string $key, string $reason, User $actor): void
    {
        if (! isset(self::LISTS[$key])) {
            throw ValidationException::withMessages(['key' => 'Unknown list.']);
        }

        AdminAudit::transaction(function () use ($key, $reason, $actor) {
            $before = self::get($key);
            DB::table('master_lists')->where('key', $key)->delete();
            AdminAudit::record('master_data.reset', null, ['list' => $key, 'items' => $before], ['list' => $key, 'items' => self::LISTS[$key]['default']], $reason, $actor);
        });

        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> what the console shows */
    public static function overview(): array
    {
        $rows = self::storedRows();

        $editable = [];

        foreach (self::LISTS as $key => $meta) {
            $editable[] = [
                'key' => $key,
                'label' => $meta['label'],
                'where' => $meta['where'],
                'items' => self::get($key),
                'default' => $meta['default'],
                'customised' => isset($rows[$key]),
                'updated_at' => $rows[$key]->updated_at ?? null,
                'choices' => $key === 'citation_styles' ? Bibliography::options() : null,
            ];
        }

        return [
            'editable' => $editable,
            'read_only' => [
                [
                    'key' => 'book_types',
                    'label' => 'Book types',
                    'why' => 'Each type carries its templates, length norms, editorial agents and questions (config/booktypes.php). Changing one is a code change, tested and deployed.',
                    'items' => array_map(fn (BookType $t) => $t->name(), BookType::all()),
                ],
                [
                    'key' => 'book_parts',
                    'label' => 'Book parts (front and back matter)',
                    'why' => 'Part names are printed by the renderers in six languages and matched by the importer (App\Publishing\BookPart). There is no override the code reads safely yet.',
                    'items' => array_map(fn (BookPart $p) => $p->chicagoName(), BookPart::ordered()),
                ],
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private static function stored(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => Schema::hasTable('master_lists')
                ? DB::table('master_lists')->pluck('items', 'key')->map(fn ($v) => json_decode((string) $v, true))->filter(fn ($v) => is_array($v) && $v !== [])->all()
                : []);
        } catch (Throwable $e) {
            // The constants: a list read must never take a page down.
            report($e);

            return [];
        }
    }

    private static function storedRows(): array
    {
        try {
            return DB::table('master_lists')->get()->keyBy('key')->all();
        } catch (Throwable) {
            return [];
        }
    }
}
