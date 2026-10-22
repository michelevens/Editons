<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use ZipArchive;

/**
 * The .ecrivain file: a whole book, packed to move.
 *
 * Every other export loses something. EPUB and DOCX are reading formats;
 * Markdown flattens the binder tree; HTML is a single document. None of them
 * can be imported back into a book that is the same book — the parts, the
 * journey state, the idea, the targets and the structure are all gone.
 *
 * This is the format that round-trips. A zip holding manifest.json (format,
 * version, what is inside, a signature), book.json (the manuscript), and
 * nothing else — there are no uploaded files in a manuscript, so unlike
 * PlanDraft's .plandraft there is no files/ directory to police.
 *
 * Following the shape PlanDraft established: the manifest is checked before
 * anything is read, the signature proves the file came from this installation
 * without being a security boundary, and an import is treated as untrusted —
 * sizes, entry names and row counts are all bounded, ids are never taken from
 * the file, and the importing account's ownership and plan limits apply.
 *
 * What it deliberately does NOT carry: version history, editorial findings,
 * comments, exports. Those belong to a book in a workspace, not to the
 * manuscript, and carrying them would mean importing somebody else's review
 * state into your own account.
 */
class PortableBook
{
    public const FORMAT = 'ecrivain';

    public const VERSION = 1;

    public const EXT = 'ecrivain';

    /** Fields that travel with the book. Ids, ownership and counts never do. */
    private const BOOK_FIELDS = [
        'title', 'subtitle', 'author_name', 'genre', 'language', 'description',
        'book_type', 'started_with', 'assistance', 'journey_stage',
        'premise', 'audience', 'promise', 'target_words',
        'layout', 'meta', 'rights',
        'series_name', 'series_number',
    ];

    /** Per-section fields. parent_id is rewired on import, never trusted. */
    private const SECTION_FIELDS = ['type', 'part', 'title', 'position', 'content'];

    private const MAX_UPLOAD = 25 * 1024 * 1024;

    private const MAX_UNPACKED = 100 * 1024 * 1024;

    private const MAX_SECTIONS = 2000;

    /**
     * Pack a book into a .ecrivain file.
     *
     * @return array{0: string, 1: string} temporary path, and the file name
     */
    public function export(Book $book): array
    {
        $sections = $book->sections()->orderBy('position')->get();

        // Sections reference their parent by position in this list rather
        // than by id: an id from another installation means nothing here, and
        // an index cannot point outside the file.
        $index = $sections->values()->mapWithKeys(
            fn (ManuscriptSection $s, int $i) => [$s->id => $i],
        );

        $data = [
            'book' => $book->only(self::BOOK_FIELDS),
            'sections' => $sections->map(fn (ManuscriptSection $s) => array_merge(
                $s->only(self::SECTION_FIELDS),
                ['parent' => $s->parent_id !== null ? ($index[$s->parent_id] ?? null) : null],
            ))->all(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $manifest = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'title' => $book->title,
            'sections' => count($data['sections']),
            'words' => $book->word_count,
            'created_at' => now()->toIso8601String(),
            'generator' => 'Ecrivain',
            // Proves the file came from this installation unchanged. Not a
            // security boundary — an import from elsewhere is still allowed,
            // and simply reports that it could not be verified.
            'signature' => hash_hmac('sha256', $json, (string) config('app.key')),
        ];

        $path = tempnam(sys_get_temp_dir(), 'ecr').'.'.self::EXT;
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('book.json', $json);
        $zip->close();

        return [$path, $this->filename($book)];
    }

    /**
     * Open and check a file without importing it.
     *
     * @return array{manifest: array<string, mixed>, data: array<string, mixed>, verified: bool}
     */
    public function inspect(string $path): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_UPLOAD) {
            throw new RuntimeException('That file is too large to be a book.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('That is not an Ecrivain book file.');
        }

        // Bound what the archive claims before reading any of it: a small
        // zip can describe an enormous expansion.
        $unpacked = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $name = $entry['name'] ?? '';

            if (! in_array($name, ['manifest.json', 'book.json'], true)) {
                $zip->close();

                throw new RuntimeException("That file contains something unexpected: {$name}");
            }

            $unpacked += (int) ($entry['size'] ?? 0);
        }

        if ($unpacked > self::MAX_UNPACKED) {
            $zip->close();

            throw new RuntimeException('That file unpacks to more than a book should.');
        }

        $manifestJson = (string) $zip->getFromName('manifest.json');
        $json = (string) $zip->getFromName('book.json');
        $zip->close();

        $manifest = json_decode($manifestJson, true);
        $data = json_decode($json, true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('That is not an Ecrivain book file.');
        }

        // A newer file may describe things this version cannot represent, so
        // it is refused rather than imported with silent losses.
        if ((int) ($manifest['version'] ?? 0) > self::VERSION) {
            throw new RuntimeException('That book was made by a newer version of Ecrivain.');
        }

        if (! is_array($data) || ! isset($data['book'], $data['sections']) || ! is_array($data['sections'])) {
            throw new RuntimeException('That file does not contain a manuscript.');
        }

        if (count($data['sections']) > self::MAX_SECTIONS) {
            throw new RuntimeException('That book has more sections than this can import.');
        }

        return [
            'manifest' => $manifest,
            'data' => $data,
            'verified' => hash_equals(
                hash_hmac('sha256', $json, (string) config('app.key')),
                (string) ($manifest['signature'] ?? ''),
            ),
        ];
    }

    /**
     * Import a file into an account as a new book.
     *
     * Always a new book. Overwriting an existing one on the strength of a
     * matching title is the kind of helpfulness that loses somebody's work.
     */
    public function import(UploadedFile|string $file, int $userId): Book
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $opened = $this->inspect($path);

        $data = $opened['data'];

        return DB::transaction(function () use ($data, $userId) {
            $attributes = $this->bookAttributes(is_array($data['book']) ? $data['book'] : []);

            // Through the relation, because user_id is deliberately not
            // fillable: ownership comes from the session, never from a file.
            $book = User::findOrFail($userId)->books()->create(array_merge($attributes, [
                'title' => $this->title($attributes['title'] ?? null),
                'status' => 'draft',
            ]));

            $created = [];

            foreach (array_values($data['sections']) as $i => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $row = $book->sections()->create([
                    'type' => in_array($section['type'] ?? null, ManuscriptSection::TYPES, true)
                        ? $section['type']
                        : 'chapter',
                    'title' => mb_substr(trim((string) ($section['title'] ?? 'Untitled')), 0, 255),
                    // A part the taxonomy does not know, or one that does not
                    // fit the section's type, is dropped and inferred again.
                    'part' => $this->part($section),
                    'position' => (int) ($section['position'] ?? ($i + 1) * ManuscriptSection::POSITION_STEP),
                    'content' => is_array($section['content'] ?? null)
                        ? $section['content']
                        : ['type' => 'doc', 'content' => []],
                ]);

                $created[$i] = $row;
            }

            // Rewire parents by index, now that every row exists. An index
            // pointing outside the file, or at itself, is dropped rather
            // than producing a cycle.
            foreach (array_values($data['sections']) as $i => $section) {
                $parent = $section['parent'] ?? null;

                if (! isset($created[$i], $created[$parent]) || $parent === $i) {
                    continue;
                }

                // forceFill: tree position is structure the server decides.
                // See ADR-0012.
                $created[$i]->forceFill(['parent_id' => $created[$parent]->id])->save();
            }

            foreach ($created as $row) {
                $row->forceFill(['word_count' => ManuscriptSection::countWords($row->content)])->save();
            }

            $book->refresh()->recalculateWordCount();

            return $book->refresh();
        });
    }

    /**
     * The book's fields from a file, held to the rules the screens apply.
     *
     * The file is untrusted, and these values go on to places the UI never
     * lets free text reach: layout ends up in the PDF's CSS and the
     * pagination arithmetic, rights and meta in the publishing checks. A
     * crafted file stored layout.font = "serif</style><script>…" verbatim,
     * which DesignController::updateLayout would have refused.
     *
     * A value that fails is dropped, not the import: the book falls back to
     * the default for that one setting, which is what a file from an older
     * version without the field would get anyway. Every file this
     * installation writes passes.
     *
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    private function bookAttributes(array $book): array
    {
        $attributes = [];

        foreach (self::bookRules() as $field => $rules) {
            if (array_key_exists($field, $book) && $this->passes($book[$field], $rules)) {
                $attributes[$field] = $book[$field];
            }
        }

        foreach (self::nestedRules() as $field => $keys) {
            $values = is_array($book[$field] ?? null) ? $book[$field] : [];
            $kept = [];

            foreach ($keys as $key => $rules) {
                if (array_key_exists($key, $values) && $this->passes($values[$key], $rules)) {
                    $kept[$key] = $values[$key];
                }
            }

            if ($kept !== []) {
                $attributes[$field] = $kept;
            }
        }

        return $attributes;
    }

    /**
     * Scalar book fields, with the rules from BookController::store and
     * ::update (the wider of the two where they differ) and JourneyController.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function bookRules(): array
    {
        return [
            // No length limit here: title() shortens an over-long one
            // rather than discarding it.
            'title' => ['nullable', 'string'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'genre' => ['nullable', 'string', 'max:120'],
            // Not nullable: these columns are NOT NULL with a default, and a
            // null from the file would fail the insert rather than default.
            'language' => ['string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],
            'book_type' => ['nullable', 'string', Rule::in(array_keys(config('booktypes')))],
            'started_with' => ['nullable', 'string', 'max:40'],
            'assistance' => [Rule::in(Book::ASSISTANCE)],
            'journey_stage' => [Rule::in(Book::JOURNEY_STAGES)],
            'premise' => ['nullable', 'string', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:2000'],
            'promise' => ['nullable', 'string', 'max:2000'],
            'target_words' => ['nullable', 'integer', 'min:100', 'max:2000000'],
            'series_name' => ['nullable', 'string', 'max:255'],
            'series_number' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ];
    }

    /**
     * The JSON columns, key by key: DesignController::updateLayout,
     * RightsController::updateRights and PublishingController::update.
     * Keys those endpoints do not accept are not carried over.
     *
     * @return array<string, array<string, array<int, mixed>>>
     */
    private static function nestedRules(): array
    {
        return [
            'layout' => [
                'trim' => ['string', Rule::in(Book::TRIMS)],
                'margins' => ['string', 'in:Narrow,Standard,Wide'],
                'font' => ['string', 'in:serif,sans'],
                'size' => ['numeric', 'min:9', 'max:14'],
                'leading' => ['numeric', 'min:1.2', 'max:1.8'],
                'dropcap' => ['boolean'],
                'headers' => ['boolean'],
            ],
            'rights' => array_map(
                fn () => ['boolean'],
                Book::DEFAULT_RIGHTS,
            ),
            'meta' => [
                'description' => ['nullable', 'string', 'max:4000'],
                'category' => ['nullable', 'string', 'max:255'],
                'keywords' => ['nullable', 'string', 'max:500'],
            ],
        ];
    }

    /** @param  array<int, mixed>  $rules */
    private function passes(mixed $value, array $rules): bool
    {
        return Validator::make(['value' => $value], ['value' => $rules])->passes();
    }

    /** The section's book part from the file, if it is valid for its type. */
    private function part(array $section): ?string
    {
        $part = BookPart::tryFrom((string) ($section['part'] ?? ''));
        $type = in_array($section['type'] ?? null, ManuscriptSection::TYPES, true) ? $section['type'] : 'chapter';

        return $part && $part->isSectionKind() && in_array($type, $part->compatibleTypes(), true)
            ? $part->value
            : null;
    }

    private function title(?string $title): string
    {
        $title = trim((string) $title);

        return $title === '' ? 'Untitled book' : mb_substr($title, 0, 255);
    }

    private function filename(Book $book): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $book->title) ?: 'book';

        return trim(strtolower($slug), '-').'.'.self::EXT;
    }
}
