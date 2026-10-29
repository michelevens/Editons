<?php

namespace App\Covers;

use App\Models\Book;
use App\Models\CoverTemplate;
use App\Models\User;
use App\Platform\House\House;
use App\Publishing\CoverRenderer;
use App\Publishing\CoverWrap;
use App\Publishing\HouseStyles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Cover templates: one book's cover design saved under a name and applied
 * to other books, each filling it with its own title, author, series
 * number, copy and ISBN (ADR — Cover backgrounds and series templates).
 *
 * A template is the cover's design and nothing of the book: the layers of
 * the front, back and spine (bound layers stay bound, so each book shows
 * its own text; custom text is the template's), the backgrounds, the
 * spine's direction and colour, the layout, faces, palette and shade. The
 * book's pictures and artwork go in only when it is saved "with images";
 * they are then copied, so deleting them from the source book does not
 * break the template, and copied again into each book it is applied to.
 * Applying replaces the cover design only — never the manuscript, the
 * metadata or the book's own cover text — and keeps what it replaced
 * (`cover.pre_template`), which "Restore the previous cover" puts back.
 * Existing renders go out of date: covers are named by their design's
 * hash, and wraps by CoverWrap::fingerprint.
 *
 * An uploaded finished front or back (the Écrivain Prépa books) is never
 * replaced silently: applying to such a book needs a choice, `replace`
 * (the template's design instead; the files stay on the disk, in the
 * snapshot) or `keep` (the uploaded artwork stays as the background, the
 * template's type and panels over it). Without one the book is skipped.
 *
 * Ownership and following work as house styles do (HouseStyles): a
 * template belongs to the account that owns the books it is made from and
 * applied to (a house account's, run by staff with two-factor), only
 * "follow" links a book, a following book's own design edits are refused
 * until it is detached, and updating the template rewrites its followers.
 */
class CoverTemplates
{
    /** The cover keys a template carries and applying replaces. */
    public const DESIGN_KEYS = [
        'font', 'palette', 'layout', 'shade', 'layers', 'back_layers', 'spine_layers',
        'spine_direction', 'backgrounds', 'wrap_ref', 'framing', 'back_framing',
    ];

    /** The book's own pictures: kept, or replaced, but never mixed in silently. */
    private const ARTWORK_KEYS = ['artwork', 'artwork_source', 'back_artwork'];

    public const ARTWORK_CHOICES = ['replace', 'keep'];

    /** Books a preview draws at once. */
    public const MAX_PREVIEW = 50;

    public function __construct(private readonly CoverRenderer $covers, private readonly CoverWrap $wraps) {}

    /** May $actor create, update, apply and delete this account's templates? */
    public static function canManage(User $actor, User $account): bool
    {
        return HouseStyles::canManage($actor, $account);
    }

    // --- Saving -------------------------------------------------------------

    /** Save a book's cover design as a new template of its owner's account. */
    public function save(Book $book, User $actor, string $name, bool $images = false, bool $follow = true): CoverTemplate
    {
        Gate::forUser($actor)->authorize('update', $book);
        $account = $book->user;
        $this->authorizeAccount($actor, $account);
        $name = trim($name);

        if (CoverTemplate::where('user_id', $account->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a cover template called “{$name}”."]);
        }

        return DB::transaction(function () use ($book, $actor, $account, $name, $images, $follow) {
            $template = new CoverTemplate(['name' => $name, 'design' => [], 'include_images' => $images]);
            $template->user_id = $account->id;
            $template->source_book_id = $book->id;
            $template->updated_by = $actor->id;
            $template->save();

            $template->design = $this->capture($book, $template);
            $template->save();

            if ($follow) {
                $this->attach($book, $template);
            }

            return $template;
        });
    }

    /**
     * Replace a template's design with a book's and rewrite every book that
     * follows it, each keeping the artwork choice it was applied with.
     *
     * @return int the books updated, the source included
     */
    public function update(CoverTemplate $template, Book $from, User $actor): int
    {
        Gate::forUser($actor)->authorize('update', $from);
        $this->authorizeAccount($actor, $template->user);
        $this->sameAccount($template, $from);

        return DB::transaction(function () use ($template, $from, $actor) {
            Storage::disk('files')->deleteDirectory("cover-templates/{$template->id}");
            $template->design = $this->capture($from, $template);
            $template->version = $template->version + 1;
            $template->source_book_id = $from->id;
            $template->updated_by = $actor->id;
            $template->save();

            $this->attach($from, $template);
            $count = 1;

            foreach (Book::where('cover_template_id', $template->id)->whereKeyNot($from->id)->lockForUpdate()->get() as $book) {
                $this->write($book, $template, true, ($book->cover ?? [])['template_artwork'] ?? 'keep');
                $count++;
            }

            return $count;
        });
    }

    public function rename(CoverTemplate $template, User $actor, string $name): CoverTemplate
    {
        $this->authorizeAccount($actor, $template->user);
        $name = trim($name);

        if (CoverTemplate::where('user_id', $template->user_id)->where('name', $name)->whereKeyNot($template->id)->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a cover template called “{$name}”."]);
        }

        $template->update(['name' => $name]);

        return $template;
    }

    /** Delete a template; its books keep their covers. */
    public function delete(CoverTemplate $template, User $actor): void
    {
        $this->authorizeAccount($actor, $template->user);

        DB::transaction(function () use ($template) {
            Book::where('cover_template_id', $template->id)->update(['cover_template_id' => null, 'cover_template_version' => null]);
            $template->delete();
        });

        Storage::disk('files')->deleteDirectory("cover-templates/{$template->id}");
    }

    /**
     * A book's cover design as a template stores it; its pictures copied
     * under the template when it includes images, left out (with the layers
     * and background that showed them) when it does not.
     *
     * @return array<string, mixed>
     */
    private function capture(Book $book, CoverTemplate $template): array
    {
        $cover = $book->cover ?? [];
        $settings = $this->covers->settings($book);
        $design = [
            'font' => $settings['font'],
            'palette' => $settings['palette'],
            'layout' => $settings['layout'],
            'shade' => $settings['shade'],
            'layers' => CoverLayers::stored($book, 'front'),
            'back_layers' => CoverLayers::stored($book, 'back'),
            'spine_layers' => CoverLayers::stored($book, 'spine'),
            'spine_direction' => in_array($cover['spine_direction'] ?? null, CoverLayers::DIRECTIONS, true) ? $cover['spine_direction'] : null,
            'backgrounds' => CoverBackground::all($book) ?: null,
            'wrap_ref' => $cover['wrap_ref'] ?? null,
            'spine_color' => $book->printSettings()['spine_color'] ?? null,
        ];

        $disk = Storage::disk('files');
        $dir = "cover-templates/{$template->id}";

        if ($template->include_images) {
            $images = [];

            foreach (CoverImages::all($book) as $entry) {
                if ($disk->exists($entry['path'])) {
                    $path = "{$dir}/images/{$entry['id']}.{$entry['type']}";
                    $disk->put($path, (string) $disk->get($entry['path']));
                    $images[] = ['path' => $path] + $entry;
                }
            }

            $design['images'] = $images;

            foreach (['artwork' => 'framing', 'back_artwork' => 'back_framing'] as $key => $framing) {
                $source = $cover[$key] ?? null;

                if (is_string($source) && $source !== '' && $disk->exists($source)) {
                    $path = "{$dir}/{$key}.".(pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg');
                    $disk->put($path, (string) $disk->get($source));
                    $design[$key] = $path;
                    $design[$framing] = $cover[$framing] ?? null;
                }
            }
        } else {
            // Without its pictures, the layers and the wrap picture that
            // showed them go too, rather than point at nothing.
            foreach (['layers', 'back_layers', 'spine_layers'] as $key) {
                if ($design[$key]) {
                    $design[$key]['items'] = array_values(array_filter($design[$key]['items'], fn ($l) => $l['source'] !== 'image'));
                }
            }

            if (($design['backgrounds']['wrap']['kind'] ?? null) === 'image') {
                unset($design['backgrounds']['wrap']);
                $design['backgrounds'] = $design['backgrounds'] ?: null;
                $design['wrap_ref'] = null;
            }
        }

        $design['measure'] = $this->measure($book, $design);
        $design['source'] = ['book' => $book->id, 'title' => $book->title];

        return $design;
    }

    // --- Applying -----------------------------------------------------------

    /**
     * Apply a template to one book: its cover design replaced, nothing else.
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function apply(CoverTemplate $template, Book $book, User $actor, bool $follow = true, ?string $artwork = null): array
    {
        Gate::forUser($actor)->authorize('update', $book);
        $this->authorizeAccount($actor, $template->user);
        $this->sameAccount($template, $book);
        $uploaded = $this->uploaded($book);

        if (($uploaded['front'] || $uploaded['back']) && ! in_array($artwork, self::ARTWORK_CHOICES, true)) {
            throw ValidationException::withMessages(['artwork' => sprintf(
                'This book has an uploaded finished %s. Choose whether the template replaces it, or keeps it as the background image; nothing was changed.',
                $uploaded['front'] && $uploaded['back'] ? 'front and back' : ($uploaded['front'] ? 'front' : 'back'),
            )]);
        }

        $before = $this->designOf($book);
        $this->write($book, $template, $follow, $artwork);

        return ['before' => $before, 'after' => $this->designOf($book->fresh())];
    }

    /**
     * Apply to several books, each on its own; $each runs inside each
     * book's transaction (the console's audit row).
     *
     * @param  list<int>  $bookIds
     * @return list<array{id: int, title: ?string, ok: bool, message: string}>
     */
    public function applyMany(CoverTemplate $template, array $bookIds, User $actor, bool $follow = true, ?string $artwork = null, ?callable $each = null): array
    {
        $books = Book::with('user')->whereIn('id', $bookIds)->get()->keyBy('id');
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $bookIds))) as $id) {
            $book = $books[$id] ?? null;

            try {
                if (! $book) {
                    throw ValidationException::withMessages(['book' => 'No such book.']);
                }

                DB::transaction(function () use ($template, $book, $actor, $follow, $artwork, $each) {
                    $change = $this->apply($template, $book, $actor, $follow, $artwork);

                    if ($each) {
                        $each($book, $change);
                    }
                });

                $results[] = ['id' => $id, 'title' => $book->title, 'ok' => true,
                    'message' => $follow ? "Follows the cover template “{$template->name}”." : "Cover copied from “{$template->name}”."];
            } catch (ValidationException $e) {
                $results[] = ['id' => $id, 'title' => $book?->title, 'ok' => false, 'message' => collect($e->errors())->flatten()->implode(' ')];
            } catch (AuthorizationException) {
                $results[] = ['id' => $id, 'title' => $book?->title, 'ok' => false, 'message' => 'You cannot change this book’s cover.'];
            }
        }

        return $results;
    }

    /** Stop following: the book keeps its cover and can be customised. */
    public function detach(Book $book, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $book);
        $book->forceFill(['cover_template_id' => null, 'cover_template_version' => null])->save();
    }

    /**
     * Put back the cover a template replaced (`cover.pre_template`): its
     * design, its artwork and its spine colour. The book stops following.
     */
    public function restore(Book $book, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $book);
        $cover = $book->cover ?? [];
        $snapshot = $cover['pre_template'] ?? null;

        if (! is_array($snapshot) || ! is_array($snapshot['cover'] ?? null)) {
            throw ValidationException::withMessages(['cover' => 'There is no earlier cover to restore.']);
        }

        foreach ([...self::DESIGN_KEYS, ...self::ARTWORK_KEYS, 'template_artwork', 'pre_template'] as $key) {
            unset($cover[$key]);
        }

        $cover = array_merge($cover, $snapshot['cover']);
        $print = $book->print ?? [];
        $print['spine_color'] = $snapshot['spine_color'] ?? null;

        $book->forceFill([
            'cover' => $cover,
            'print' => array_filter($print, fn ($v) => $v !== null),
            'cover_template_id' => null,
            'cover_template_version' => null,
        ])->save();
    }

    /**
     * Refuse a cover design change to a book that follows a template.
     *
     * @throws ValidationException
     */
    public static function refuseWhileFollowing(Book $book): void
    {
        if ($book->cover_template_id !== null && ($template = $book->coverTemplate)) {
            throw ValidationException::withMessages(['cover_template' => "This book’s cover follows the template “{$template->name}”. Choose “Customise this book’s cover” to change its design on its own."]);
        }
    }

    /** Which of the book's own finished sides are uploaded pictures. @return array{front: bool, back: bool} */
    public function uploaded(Book $book): array
    {
        $cover = $book->cover ?? [];
        $disk = Storage::disk('files');
        $has = fn (?string $path) => is_string($path) && $path !== '' && $disk->exists($path);

        return ['front' => $has($cover['artwork'] ?? null), 'back' => $has($cover['back_artwork'] ?? null)];
    }

    /**
     * The cover and print settings a book would have with the template:
     * the design replaced, the book's own text kept, its uploaded artwork
     * kept or set aside as chosen. With $copy false nothing is written to
     * disk (the preview draws straight from the template's files).
     *
     * @return array{cover: array<string, mixed>, print: array<string, mixed>}
     */
    public function build(Book $book, CoverTemplate $template, ?string $artwork, bool $copy): array
    {
        $cover = $book->cover ?? [];
        $design = $template->design ?? [];
        $disk = Storage::disk('files');
        $following = $book->cover_template_id === $template->id;

        // What it replaces, once: re-applying (an update reaching a
        // follower) keeps the cover from before the template.
        if (! $following || ! isset($cover['pre_template'])) {
            $cover['pre_template'] = [
                'template' => $template->name,
                'at' => now()->toIso8601String(),
                'cover' => array_intersect_key($cover, array_flip([...self::DESIGN_KEYS, ...self::ARTWORK_KEYS, 'images'])),
                'spine_color' => $book->printSettings()['spine_color'] ?? null,
            ];
        }

        foreach (self::DESIGN_KEYS as $key) {
            unset($cover[$key]);

            if (isset($design[$key]) && $design[$key] !== null) {
                $cover[$key] = $design[$key];
            }
        }

        // The template's pictures, under new ids in this book (or, for a
        // preview, as they are), and the layers and background pointed at
        // them. Pictures an earlier template brought are replaced.
        $images = array_values(array_filter(CoverImages::all($book), fn ($i) => ! ($i['template'] ?? false)));
        $map = [];

        foreach ($design['images'] ?? [] as $entry) {
            $id = $copy ? 'img'.Str::lower(Str::random(10)) : $entry['id'];
            $path = $copy ? "covers/book-{$book->id}/images/{$id}.{$entry['type']}" : $entry['path'];

            if ($copy) {
                $disk->put($path, (string) $disk->get($entry['path']));
            }

            $map[$entry['id']] = $id;
            $images[] = ['id' => $id, 'path' => $path] + $entry + ['template' => true];
        }

        if ($copy) {
            foreach (CoverImages::all($book) as $old) {
                if ($old['template'] ?? false) {
                    $disk->delete($old['path']);
                }
            }
        }

        $cover['images'] = $images;

        foreach (CoverLayers::STORED as $key) {
            foreach ($cover[$key]['items'] ?? [] as $i => $layer) {
                if (($layer['source'] ?? null) === 'image') {
                    $cover[$key]['items'][$i]['image'] = $map[$layer['image']] ?? $layer['image'];
                }
            }
        }

        if (isset($cover['backgrounds']['wrap']['image'])) {
            $cover['backgrounds']['wrap']['image'] = $map[$cover['backgrounds']['wrap']['image']] ?? $cover['backgrounds']['wrap']['image'];
        }

        // The book's own uploaded artwork: kept as the background, or set
        // aside (the files stay, in the snapshot) for the template's.
        $uploaded = $this->uploaded($book);

        if ($artwork === 'keep' && ($uploaded['front'] || $uploaded['back'])) {
            foreach (['framing', 'back_framing'] as $key) {
                if (isset($cover['pre_template']['cover'][$key])) {
                    $cover[$key] = $cover['pre_template']['cover'][$key];
                }
            }
        } else {
            unset($cover['artwork'], $cover['artwork_source'], $cover['back_artwork'], $cover['framing'], $cover['back_framing']);

            foreach (['artwork' => 'framing', 'back_artwork' => 'back_framing'] as $key => $framing) {
                if (! empty($design[$key]) && $disk->exists($design[$key])) {
                    $path = $copy ? "covers/book-{$book->id}/{$key}-template-".substr(md5($design[$key].$template->version), 0, 10).'.'.(pathinfo($design[$key], PATHINFO_EXTENSION) ?: 'jpg') : $design[$key];

                    if ($copy) {
                        $disk->put($path, (string) $disk->get($design[$key]));
                    }

                    $cover[$key] = $path;

                    if (! empty($design[$framing])) {
                        $cover[$framing] = $design[$framing];
                    }
                }
            }

            if (isset($cover['artwork'])) {
                $cover['artwork_source'] = ['type' => 'template', 'template' => $template->name];
            }
        }

        $cover['template_artwork'] = $artwork;
        $print = $book->print ?? [];
        $print['spine_color'] = $design['spine_color'] ?? null;

        return ['cover' => $cover, 'print' => array_filter($print, fn ($v) => $v !== null)];
    }

    private function write(Book $book, CoverTemplate $template, bool $follow, ?string $artwork): void
    {
        $built = $this->build($book, $template, $artwork, true);

        $book->forceFill([
            'cover' => $built['cover'],
            'print' => $built['print'],
            'cover_template_id' => $follow ? $template->id : null,
            'cover_template_version' => $follow ? $template->version : null,
        ])->save();
    }

    /** A book's cover design, for the audit's before and after. @return array<string, mixed> */
    private function designOf(Book $book): array
    {
        $cover = $book->cover ?? [];

        return [
            'cover_template_id' => $book->cover_template_id,
            'layout' => $cover['layout'] ?? null,
            'layers' => isset($cover['layers']) ? count($cover['layers']['items'] ?? []) : null,
            'back_layers' => isset($cover['back_layers']) ? count($cover['back_layers']['items'] ?? []) : null,
            'spine_layers' => isset($cover['spine_layers']) ? count($cover['spine_layers']['items'] ?? []) : null,
            'backgrounds' => array_keys($cover['backgrounds'] ?? []) ?: null,
            'artwork' => $cover['artwork'] ?? null,
            'back_artwork' => $cover['back_artwork'] ?? null,
        ];
    }

    // --- Preview ------------------------------------------------------------

    /**
     * What applying would do to each book, before anything changes: a
     * thumbnail of its front with the template, whether it needs the
     * artwork choice, and where its own text overflows the template's boxes.
     *
     * @param  list<int>  $bookIds
     * @return list<array<string, mixed>>
     */
    public function preview(CoverTemplate $template, array $bookIds, User $actor, ?string $artwork = null): array
    {
        $this->authorizeAccount($actor, $template->user);
        $ids = array_slice(array_values(array_unique(array_map('intval', $bookIds))), 0, self::MAX_PREVIEW);
        $books = Book::with('user')->whereIn('id', $ids)->get()->keyBy('id');
        $gate = Gate::forUser($actor);
        $rows = [];

        foreach ($ids as $id) {
            $book = $books[$id] ?? null;

            if (! $book || ! $gate->allows('view', $book)) {
                $rows[] = ['id' => $id, 'title' => null, 'ok' => false, 'message' => 'Not found.', 'flags' => [], 'thumb' => null];

                continue;
            }

            if (! $gate->allows('update', $book) || $book->user_id !== $template->user_id) {
                $rows[] = ['id' => $id, 'title' => $book->title, 'ok' => false, 'flags' => [], 'thumb' => null,
                    'message' => $book->user_id !== $template->user_id ? 'This template belongs to another account.' : 'You can read this book but not change it.'];

                continue;
            }

            $uploaded = $this->uploaded($book);
            $virtual = $this->virtual($book, $template, $artwork);

            try {
                $thumb = 'data:image/jpeg;base64,'.base64_encode($this->covers->scale($this->renderFront($virtual), 200));
            } catch (Throwable $e) {
                report($e);
                $thumb = null;
            }

            $rows[] = [
                'id' => $id,
                'title' => $book->title,
                'ok' => true,
                'message' => '',
                'uploaded' => $uploaded,
                'needs_choice' => ($uploaded['front'] || $uploaded['back']) && ! in_array($artwork, self::ARTWORK_CHOICES, true),
                'house' => House::isHouseBook($book),
                'flags' => $this->overflow($virtual, $template),
                'thumb' => $thumb,
            ];
        }

        return $rows;
    }

    /** The book as it would be with the template, in memory only. */
    private function virtual(Book $book, CoverTemplate $template, ?string $artwork): Book
    {
        $built = $this->build($book, $template, $artwork ?? 'keep', false);
        $virtual = $book->replicate();
        $virtual->id = $book->id;
        $virtual->exists = true;
        $virtual->cover = $built['cover'];
        $virtual->print = $built['print'];

        return $virtual;
    }

    private function renderFront(Book $book): string
    {
        $settings = $this->covers->settings($book);
        $disk = Storage::disk('files');
        $art = $settings['artwork'] && $disk->exists($settings['artwork']) ? $disk->get($settings['artwork']) : null;

        return $this->covers->render($settings, $art);
    }

    /**
     * Each visible text layer's lines and box, per panel, with a book's own
     * text: what overflow() compares a book against. The back and spine
     * at the book's own wrap geometry, when it can be worked out.
     *
     * @return array<string, array<string, array{lines: int, box: array<string, float>}>>
     */
    public function measure(Book $book, ?array $design = null): array
    {
        $out = [];
        $virtual = $book;

        if ($design !== null) {
            $virtual = $book->replicate();
            $virtual->id = $book->id;
            $virtual->cover = array_merge($book->cover ?? [], array_intersect_key($design, array_flip(['layers', 'back_layers', 'spine_layers'])));
        }

        $panels = ['front' => [CoverLayers::stored($virtual, 'front'), ['width' => CoverRenderer::WIDTH, 'height' => CoverRenderer::HEIGHT], CoverLayers::texts($virtual)]];

        if (CoverLayers::stored($virtual, 'back') || CoverLayers::stored($virtual, 'spine')) {
            try {
                $surfaces = $this->wraps->surfaces($this->wraps->forBook($virtual));
                $texts = CoverLayers::wrapTexts($virtual);
                $panels['back'] = [CoverLayers::stored($virtual, 'back'), $surfaces['back'], $texts];
                $panels['spine'] = [CoverLayers::stored($virtual, 'spine'), $surfaces['spine'], $texts];
            } catch (RuntimeException) {
                // A service whose spine must be typed in: the front alone.
            }
        }

        foreach ($panels as $panel => [$doc, $surface, $texts]) {
            foreach ($doc['items'] ?? [] as $layer) {
                if (! $layer['visible']) {
                    continue;
                }

                $set = CoverRenderer::place($layer, $surface, $texts);
                $box = $set['box'];
                $out[$panel][$layer['id']] = [
                    'lines' => count($set['lines']),
                    'text' => $layer['source'] !== 'image',
                    'box' => [
                        'x' => $box['x'] / $surface['width'], 'y' => $box['y'] / $surface['height'],
                        'width' => $box['width'] / $surface['width'], 'height' => $box['height'] / $surface['height'],
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * Where a book's own text no longer fits the template: a box that
     * wraps to more lines than it did in the book the template was saved
     * from, runs past the panel's foot (the front's safe margin), or now
     * runs into another layer it cleared there.
     *
     * @return list<string>
     */
    public function overflow(Book $virtual, CoverTemplate $template): array
    {
        $then = $template->design['measure'] ?? [];
        $now = $this->measure($virtual);
        $flags = [];
        $name = fn (string $panel, string $id) => $this->layerName($template, $panel, $id);
        $overlap = fn (array $a, array $b) => $a['x'] < $b['x'] + $b['width'] - 1e-4 && $a['x'] + $a['width'] > $b['x'] + 1e-4
            && $a['y'] < $b['y'] + $b['height'] - 1e-4 && $a['y'] + $a['height'] > $b['y'] + 1e-4;

        foreach ($now as $panel => $layers) {
            $prefix = $panel === 'front' ? '' : ucfirst($panel).': ';
            $foot = $panel === 'front' ? 1 - CoverLayers::SAFE * CoverRenderer::WIDTH / CoverRenderer::HEIGHT : 1.0;

            foreach ($layers as $id => $m) {
                if (! $m['text'] || $m['lines'] === 0) {
                    continue;
                }

                $before = $then[$panel][$id] ?? null;

                if ($before && $m['lines'] > $before['lines']) {
                    $flags[] = $prefix.sprintf('%s wraps to %d lines (%d in the template).', $name($panel, $id), $m['lines'], $before['lines']);
                }

                if ($panel !== 'spine' && $m['box']['y'] + $m['box']['height'] > $foot + 1e-4) {
                    $flags[] = $prefix.$name($panel, $id).' runs past the bottom of the '.($panel === 'front' ? 'safe area' : 'panel').'.';
                }

                foreach ($layers as $other => $o) {
                    if ($other === $id || ! $overlap($m['box'], $o['box'])) {
                        continue;
                    }

                    $was = isset($then[$panel][$id], $then[$panel][$other]) && $overlap($then[$panel][$id]['box'], $then[$panel][$other]['box']);

                    if (! $was) {
                        $flags[] = $prefix.$name($panel, $id).' now runs into '.lcfirst($name($panel, $other)).'.';
                    }
                }
            }
        }

        return array_values(array_unique($flags));
    }

    private function layerName(CoverTemplate $template, string $panel, string $id): string
    {
        $key = CoverLayers::STORED[$panel];

        foreach ($template->design[$key]['items'] ?? [] as $layer) {
            if ($layer['id'] === $id) {
                return match ($layer['source']) {
                    'custom' => 'The text “'.Str::limit((string) $layer['text'], 24).'”',
                    'image' => 'The picture',
                    'number' => 'The series number',
                    'description' => 'The back-cover copy',
                    default => 'The '.$layer['source'],
                };
            }
        }

        return 'A layer';
    }

    // --- Listing ------------------------------------------------------------

    /**
     * What Cover Studio shows about templates for this book.
     *
     * @return array<string, mixed>
     */
    public static function summary(Book $book, ?User $actor): array
    {
        $template = $book->cover_template_id ? $book->coverTemplate : null;
        $manage = $actor && $book->user && self::canManage($actor, $book->user);

        return [
            'following' => $template ? ['id' => $template->id, 'name' => $template->name, 'version' => $template->version,
                'current' => $book->cover_template_version === $template->version] : null,
            'can_manage' => $manage,
            'restorable' => isset(($book->cover ?? [])['pre_template']['cover']),
            'house' => House::isHouseBook($book),
            'uploaded' => app(self::class)->uploaded($book),
            'templates' => $manage ? self::listed(CoverTemplate::where('user_id', $book->user_id)) : [],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listed($query): array
    {
        return $query->withCount('books')->orderBy('name')->get()
            ->map(fn (CoverTemplate $t) => [
                'id' => $t->id, 'name' => $t->name, 'version' => $t->version, 'books' => $t->books_count,
                'include_images' => $t->include_images, 'house_id' => $t->user_id,
                'summary' => self::describe($t->design ?? []),
            ])->all();
    }

    /** A one-line description: "Layers on front, back and spine · gradient across the wrap". */
    public static function describe(array $design): string
    {
        $panels = array_keys(array_filter(['front' => $design['layers'] ?? null, 'back' => $design['back_layers'] ?? null, 'spine' => $design['spine_layers'] ?? null]));
        $parts = [$panels === [] ? (CoverRenderer::LAYOUTS[$design['layout'] ?? 'classic'] ?? 'Classic').' layout' : 'Layers on '.self::listWords($panels)];

        foreach (($design['backgrounds'] ?? null) ?: [] as $scope => $bg) {
            $parts[] = ($bg['kind'] === 'image' ? 'picture' : ($bg['kind'] === 'solid' ? 'colour' : 'gradient')).($scope === 'wrap' ? ' across the wrap' : " on the {$scope}");
        }

        if (! empty($design['images']) || ! empty($design['artwork'])) {
            $parts[] = 'with images';
        }

        return implode(' · ', $parts);
    }

    private static function listWords(array $words): string
    {
        return count($words) < 2 ? implode('', $words) : implode(', ', array_slice($words, 0, -1)).' and '.end($words);
    }

    private function attach(Book $book, CoverTemplate $template): void
    {
        $book->forceFill(['cover_template_id' => $template->id, 'cover_template_version' => $template->version])->save();
    }

    private function authorizeAccount(User $actor, ?User $account): void
    {
        if (! $account || ! self::canManage($actor, $account)) {
            throw new AuthorizationException('You cannot manage this account’s cover templates.');
        }
    }

    private function sameAccount(CoverTemplate $template, Book $book): void
    {
        if ($template->user_id !== $book->user_id) {
            throw ValidationException::withMessages(['book' => 'A cover template can only be applied to books of the account that owns it.']);
        }
    }
}
