<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\I18n\Locales;
use App\Jobs\GenerateExport;
use App\Mail\Notify;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\BookExport;
use App\Models\ExportProfile;
use App\Platform\House\House;
use App\Platform\Trust\MasterData;
use App\Publishing\BookPart;
use App\Publishing\Exporter;
use App\Publishing\ExportOptions;
use App\Publishing\SubmissionCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Export & Publish.
 *
 * Everything here is gated on `update`, not `view`: submitting a book to a
 * store and generating files under the author's name are not things a reviewer
 * invited to comment should be able to do.
 */
class PublishingController extends Controller
{
    public function __construct(
        private readonly Exporter $exporter,
        private readonly SubmissionCheck $check,
        private readonly Entitlements $entitlements,
    ) {}

    public function show(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        return response()->json($this->payload($book));
    }

    /** Store listing metadata. */
    public function update(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'author_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'keywords' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Nullable so an author can clear a price they set by mistake,
            // rather than being stuck with a number they cannot remove.
            'list_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.99'],
            // The paperback's own price: print and ebook are priced apart,
            // and a store compares them (KDP's 70% needs the ebook 20% under).
            'print_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.99'],
            'isbn' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        // The three book columns, then the listing copy that lives in meta.
        foreach (['title', 'author_name', 'list_price', 'isbn'] as $field) {
            if (array_key_exists($field, $data)) {
                $book->{$field} = $data[$field];
            }
        }

        $meta = $book->meta ?? [];

        foreach (['description', 'category', 'keywords', 'print_price'] as $field) {
            if (array_key_exists($field, $data)) {
                $meta[$field] = $data[$field];
            }
        }

        $book->meta = $meta;
        $book->save();

        return response()->json($this->payload($book->fresh()));
    }

    /** Turn a store on or off for this book. */
    public function toggleChannel(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'channel' => ['required', 'string', 'in:'.implode(',', array_keys(BookChannel::CHANNELS))],
            'enabled' => ['required', 'boolean'],
        ]);

        $existing = $book->channels()->where('channel', $data['channel'])->first();

        if (! $data['enabled']) {
            // A live book is not unpublished by unticking a box: a store has
            // to be told, and the listing stays up until it is. Unpublishing
            // is its own action.
            abort_if(
                $existing && $existing->status === 'live',
                422,
                'This book is live in that store. Unpublish it there first.',
            );

            $existing?->delete();

            return response()->json($this->payload($book->fresh()));
        }

        if (! $existing) {
            $book->channels()->create([
                'channel' => $data['channel'],
                'format' => BookChannel::CHANNELS[$data['channel']][1],
            ]);
        }

        return response()->json($this->payload($book->fresh()));
    }

    /**
     * Get the book ready for every selected store.
     *
     * Returns 422 with the problems keyed by field when the book is not
     * ready. The screen shows each message against its own input; a flat
     * list would leave the author hunting for the field.
     *
     * Nothing is sent to any store: there is no store integration yet. This
     * used to mark each store "in review" and say "Submitted", and the
     * author waited on a review nobody had been asked for. Each store is
     * now `ready_to_submit`, the response says what to upload where, and
     * the author marks a store submitted once they have done it.
     */
    public function submit(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        if ($errors = $this->check->errors($book)) {
            return response()->json([
                'message' => Locales::t('publish.not_ready'),
                'errors' => $errors,
            ], 422);
        }

        $pending = $book->channels()->where('status', 'not_submitted')->get();

        if ($pending->isEmpty()) {
            return response()->json([
                'message' => Locales::t('publish.all_ready'),
                'errors' => ['channels' => 'Nothing new to submit.'],
            ], 422);
        }

        foreach ($pending as $channel) {
            $channel->forceFill(['status' => 'ready_to_submit'])->save();
        }

        Notify::submittedToStores($book, $pending->pluck('channel')->all(), $request->user());

        return response()->json([
            ...$this->payload($book->fresh()),
            'message' => Locales::t('publish.ready_to_upload'),
            'next_steps' => $pending->map(fn (BookChannel $channel) => $this->uploadSteps($channel->channel))->values()->all(),
        ]);
    }

    /**
     * Record that the author uploaded the book to a store.
     *
     * The author is the one who knows: they did it, at the store, outside
     * Écrivain. From here the store reviews it, so in_review is now true
     * and submitted_at is the date the author gave it to them.
     */
    public function markSubmitted(Book $book, BookChannel $channel): JsonResponse
    {
        $this->authorize('update', $book);

        abort_unless($channel->book_id === $book->id, 404);
        abort_unless($channel->status === 'ready_to_submit', 422, 'Get the book ready for this store first.');

        $channel->forceFill([
            'status' => 'in_review',
            'submitted_at' => now(),
        ])->save();

        if ($book->status === 'draft') {
            $book->update(['status' => 'in_review']);
        }

        return response()->json($this->payload($book->fresh()));
    }

    /** Pull a book back from a store it is live in. */
    public function unpublish(Book $book, BookChannel $channel): JsonResponse
    {
        $this->authorize('update', $book);

        abort_unless($channel->book_id === $book->id, 404);

        // Only a book a store has can be pulled from it; anything else is
        // unticking the store, and the CHECK constraint would refuse a row
        // with no submission date anyway.
        abort_unless(in_array($channel->status, ['in_review', 'live'], true), 422, 'This store does not have the book.');

        // submitted_at is kept: it is a record of what happened, and the
        // CHECK constraint requires it for any status but not_submitted.
        $channel->forceFill([
            'status' => 'unpublished',
            'went_live_at' => null,
        ])->save();

        Notify::bookUnpublished($channel->fresh('book'), byAuthor: true);

        return response()->json($this->payload($book->fresh()));
    }

    /** Generate a file. */
    public function generate(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'format' => ['required', 'string', 'in:'.implode(',', array_keys(BookExport::FORMATS))],
            // A cover wrap's service, paper and (where the service gives it
            // only through its template generator) spine width.
            'options' => ['sometimes', 'array'],
            'options.service' => ['sometimes', 'string', 'max:20'],
            'options.paper' => ['sometimes', 'string', 'max:20'],
            'options.spine' => ['sometimes', 'nullable', 'numeric'],
            // The export profile (ExportOptions): a preset, a saved
            // profile's id, or options of the author's own. Checked in
            // full by ExportOptions::validate, which refuses unknown keys.
            'profile' => ['sometimes', 'nullable', 'array'],
        ]);

        $options = ($data['options'] ?? []) + ($request->input('profile') ? ['profile' => (array) $request->input('profile')] : []);

        // The plan decides which formats are available. The method existed
        // and nothing called it, so every plan could export everything.
        // A house book is held to the imprint's plan, not to the staff
        // member's own (App\Platform\House\House::accountFor).
        $this->entitlements->assertCanExport(House::accountFor($book, $request->user()), $this->planFormat($data['format']));

        // PDF and EPUB take minutes for a long book, so they render on a
        // worker and the screen polls; the small formats stay immediate.
        $queued = in_array($data['format'], GenerateExport::FORMATS, true);

        try {
            $export = $queued
                ? $this->exporter->start($book, $data['format'], $request->user()->id, $options)
                : $this->exporter->generate($book, $data['format'], $request->user()->id, $options);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // 422 rather than 500: an empty manuscript is the author's to
            // fix, and the message says what to do about it.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // start() hands back a PDF or EPUB already rendering rather than
        // queueing the same book twice; only a new row needs a worker.
        if ($queued && $export->wasRecentlyCreated) {
            try {
                GenerateExport::dispatch($export->id);
            } catch (Throwable $e) {
                // A row nothing will render must not say "generating".
                BookExport::whereKey($export->id)->where('status', 'generating')->update(['status' => 'failed']);

                throw $e;
            }
        }

        return response()->json([
            'export' => $this->exportRow($export->fresh()),
            'already' => $queued && ! $export->wasRecentlyCreated,
            'book' => $this->payload($book->fresh()),
        ], $queued ? 202 : 200);
    }

    /**
     * Download a generated file.
     *
     * Served through here rather than from a public URL: these are unpublished
     * manuscripts, and a guessable path would hand one to anybody.
     */
    public function download(Request $request, Book $book, BookExport $export): StreamedResponse
    {
        $this->authorize('view', $book);

        abort_unless($export->book_id === $book->id, 404);
        // An expired row says why there is nothing to download.
        abort_if($export->status === 'expired', 410, 'That file is no longer stored. Generate it again.');
        abort_unless($export->status === 'ready', 404);

        /*
         * The file has gone.
         *
         * Exports written before the files disk moved to the bucket lived on
         * the container's disk, and a deploy took them while the row still
         * said ready. The row becomes `expired`, so the history stops
         * offering a download that cannot work.
         *
         * Making it again is generating a file under the author's name, so
         * it takes what Generate takes: update on the book and a plan with
         * the format. A reader invited to view used to be able to queue a
         * PDF render with every click on a stale link.
         */
        if (! $export->path || ! Storage::disk('files')->exists($export->path)) {
            BookExport::whereKey($export->id)->where('status', 'ready')->update(['status' => 'expired']);

            $user = $request->user();

            abort_unless(
                $user->can('update', $book) && House::accountFor($book, $user)->canExportFormat($this->planFormat($export->format)),
                410,
                'That file is no longer stored. Ask the book\'s author to generate it again.',
            );

            // A PDF or EPUB takes too long to rebuild while the browser
            // waits: queue it (or join the render already running), and the
            // history shows it when it is ready.
            // Made again with what it was made with: the cover's service,
            // or the export profile, already checked when it was first made.
            $again = $export->format === 'cover_wrap' ? ($export->options ?? []) : ['profile' => ExportOptions::fromExport($export)];

            if (in_array($export->format, GenerateExport::FORMATS, true)) {
                $rebuild = $this->exporter->start($book, $export->format, $user->id, $again);

                if ($rebuild->wasRecentlyCreated) {
                    GenerateExport::dispatch($rebuild->id);
                }

                abort(503, Locales::t('export.rebuilding'));
            }

            $export = $this->exporter->generate($book, $export->format, $user->id, $again);

            abort_unless(
                $export->status === 'ready' && $export->path && Storage::disk('files')->exists($export->path),
                503,
                'That file could not be rebuilt. Try generating it again.',
            );
        }

        return Storage::disk('files')->download(
            $export->path,
            basename($export->path),
        );
    }

    /**
     * Save the options as a profile of this book. Names are unique per
     * book whatever their case.
     */
    public function storeProfile(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'options' => ['present', 'array'],
        ]);

        $profile = new ExportProfile([
            'name' => $this->profileName($book, $data['name']),
            'options' => $this->profileOptions($book, (array) $request->input('options')),
        ]);
        $profile->book_id = $book->id;
        $profile->user_id = $request->user()->id;
        $profile->save();

        return response()->json(['profile' => $profile->id] + $this->payload($book->fresh()), 201);
    }

    public function updateProfile(Request $request, Book $book, ExportProfile $profile): JsonResponse
    {
        $this->authorize('update', $book);
        abort_unless($profile->book_id === $book->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'options' => ['sometimes', 'array'],
        ]);

        if (isset($data['name'])) {
            $profile->name = $this->profileName($book, $data['name'], $profile->id);
        }

        if ($request->has('options')) {
            $profile->options = $this->profileOptions($book, (array) $request->input('options'));
        }

        $profile->save();

        return response()->json($this->payload($book->fresh()));
    }

    public function destroyProfile(Book $book, ExportProfile $profile): JsonResponse
    {
        $this->authorize('update', $book);
        abort_unless($profile->book_id === $book->id, 404);

        // Exports made with it keep their options (BookExport.options).
        $profile->delete();

        return response()->json($this->payload($book->fresh()));
    }

    /**
     * The book's own front and back matter per format family (E3): what
     * every export of it leaves out of the EPUB, and of the print files.
     * Empty, the default, is the book's anatomy as it is.
     */
    public function matter(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $keys = implode(',', ExportOptions::matterKeys());
        $data = $request->validate([
            'ebook' => ['present', 'array'],
            'ebook.*' => ['string', 'distinct', 'in:'.$keys],
            'print' => ['present', 'array'],
            'print.*' => ['string', 'distinct', 'in:'.$keys],
        ]);

        if ($unknown = array_diff(array_keys($request->all()), ['ebook', 'print'])) {
            throw ValidationException::withMessages([reset($unknown) => Locales::t('export.no_matter_set', ['name' => reset($unknown)])]);
        }

        $meta = $book->meta ?? [];
        $meta['export_matter'] = ['ebook' => array_values($data['ebook']), 'print' => array_values($data['print'])];

        if ($meta['export_matter'] === ['ebook' => [], 'print' => []]) {
            unset($meta['export_matter']);
        }

        $book->meta = $meta;
        $book->save();

        return response()->json($this->payload($book->fresh()));
    }

    private function profileName(Book $book, string $name, ?int $except = null): string
    {
        $name = trim($name);
        $taken = ExportProfile::where('book_id', $book->id)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($name === '' || $taken || in_array(mb_strtolower($name), array_map(fn ($key) => mb_strtolower(ExportOptions::presetName($key)), ExportOptions::PRESETS), true)) {
            throw ValidationException::withMessages(['name' => $name === '' ? Locales::t('export.profile_name') : Locales::t('export.profile_exists', ['name' => $name])]);
        }

        return $name;
    }

    /** Options checked as an export would check them, stored without their name. */
    private function profileOptions(Book $book, array $input): array
    {
        unset($input['id']);

        return array_diff_key(ExportOptions::validate($input, $book)->values, array_flip(['id', 'name']));
    }

    /**
     * What the Export screen needs to offer profiles: the presets, this
     * book's saved profiles, the matter keys by group, the sections a range
     * can run between, and the book's matter set per format.
     *
     * @return array<string, mixed>
     */
    private function profiles(Book $book): array
    {
        $chapters = ExportOptions::chapterIds($book);
        $titles = $book->sections()->pluck('title', 'id');
        $label = fn (string $key) => match ($key) {
            'other_front' => 'Other front matter',
            'other_back' => 'Other back matter',
            default => BookPart::from($key)->title('en'),
        };

        return [
            'presets' => ExportOptions::presets(),
            'saved' => ExportProfile::where('book_id', $book->id)->orderBy('name')->get()
                ->map(fn (ExportProfile $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'options' => array_merge(ExportOptions::defaults(), $p->options ?? [], ['id' => $p->id, 'name' => $p->name]),
                    'summary' => ExportOptions::summary($p->options ?? []),
                ])->values()->all(),
            'matter' => [
                'front' => array_map(fn ($key) => ['key' => $key, 'label' => $label($key)], ExportOptions::matterKeys('front')),
                'back' => array_map(fn ($key) => ['key' => $key, 'label' => $label($key)], ExportOptions::matterKeys('back')),
            ],
            'sections' => collect(ExportOptions::bodySectionIds($book))
                ->map(fn (int $id) => ['id' => $id, 'title' => (string) ($titles[$id] ?? ''),
                    'chapter' => ($n = array_search($id, $chapters, true)) === false ? null : $n + 1])
                ->values()->all(),
            'chapters' => count($chapters),
            'book_matter' => [
                'ebook' => ExportOptions::bookMatter($book, 'epub'),
                'print' => ExportOptions::bookMatter($book, 'pdf'),
            ],
            'formats' => ExportOptions::FORMATS,
            'manuscript_formats' => ExportOptions::MANUSCRIPT_FORMATS,
            'watermarked' => ExportOptions::WATERMARKED,
            'watermark_max' => ExportOptions::WATERMARK_MAX,
            'page_notes_reason' => ExportOptions::PAGE_NOTES_REASON,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        // Settle this book's dead renders before listing them, so the
        // screen never waits on a row nothing will finish, whether or not
        // the scheduler is running.
        $this->exporter->expireStale($book->id);

        $meta = $book->meta ?? [];
        $channels = $book->channels()->get()->keyBy('channel');

        return [
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'author_name' => $book->author_name,
                'description' => $meta['description'] ?? '',
                'category' => $meta['category'] ?? '',
                'keywords' => $meta['keywords'] ?? '',
                'list_price' => $book->list_price,
                'print_price' => $meta['print_price'] ?? null,
                'isbn' => $book->isbn,
                'word_count' => $book->word_count,
                'status' => $book->status,
            ],
            // A fixed list rather than free text: stores match against
            // their own taxonomy, and a typed category is a rejected
            // submission. Owner-edited (App\Platform\Trust\MasterData).
            'categories' => MasterData::with('store_categories', $meta['category'] ?? null),
            'channels' => collect(BookChannel::CHANNELS)
                ->map(function (array $meta, string $key) use ($channels) {
                    $row = $channels->get($key);

                    return [
                        // The row id, needed to unpublish. Null until the
                        // store is selected and no row exists yet.
                        'id' => $row?->id,
                        'channel' => $key,
                        'label' => $meta[0],
                        'format' => $meta[1],
                        'enabled' => $row !== null,
                        'status' => $row?->status ?? 'not_submitted',
                        'submitted_at' => $row?->submitted_at?->diffForHumans(),
                        'went_live_at' => $row?->went_live_at?->diffForHumans(),
                        'upload' => $this->uploadSteps($key),
                    ];
                })
                ->values()
                ->all(),
            'formats' => collect(BookExport::FORMATS)
                ->map(fn (array $meta, string $key) => [
                    'format' => $key,
                    'label' => $meta[0],
                    'description' => $meta[2],
                    // Shown as locked rather than hidden: an author should be
                    // able to see what a paid plan would give them.
                    'available' => $book->user->canExportFormat($this->planFormat($key)),
                ])
                ->values()
                ->all(),
            'exports' => $book->exports()->limit(25)->get()
                ->map(fn (BookExport $export) => $this->exportRow($export))
                ->all(),
            // Shown as guidance before submitting, not only after a failed
            // attempt: an author should be able to see what is left to do.
            'blockers' => $this->check->errors($book),
            // Not blocking, but worth knowing before the files go anywhere.
            'warnings' => $this->check->warnings($book),
            // Export profiles (ExportOptions).
            'profiles' => $this->profiles($book),
        ];
    }

    /**
     * What the author does at one store.
     *
     * @return array{channel: string, label: string, url: ?string, file: ?string, steps: ?string}
     */
    private function uploadSteps(string $channel): array
    {
        [$url, $file, $steps] = BookChannel::UPLOAD[$channel] ?? [null, null, null];

        return [
            'channel' => $channel,
            'label' => BookChannel::CHANNELS[$channel][0] ?? $channel,
            'url' => $url,
            'file' => $file,
            'steps' => $steps,
        ];
    }

    /** @return array<string, mixed> */
    private function exportRow(BookExport $export): array
    {
        return [
            'id' => $export->id,
            'format' => $export->format,
            'label' => $export->label(),
            'status' => $export->status,
            // Seconds the screen should keep waiting for a generating row:
            // past this the sweep fails it, so polling stops there too.
            'wait_seconds' => $export->status === 'generating'
                ? max(0, BookExport::STALE_AFTER - (int) $export->updated_at->diffInSeconds(now(), true))
                : null,
            'word_count' => $export->word_count,
            'size' => $export->byte_size ? $this->humanSize($export->byte_size) : null,
            'pages' => $export->pages,
            'created_at' => $export->created_at->diffForHumans(),
            // A Sample or a review copy is for reading: never sent to a
            // store, a printer or a buyer.
            'full_book' => $export->isFullBook(),
            'profile' => $export->isFullBook() ? null : ExportOptions::fromExport($export)->label(),
            // What it was made with, to make it again (without the saved
            // profile it came from, which may since have changed).
            'profile_options' => $export->isFullBook() ? null : array_diff_key($export->options['profile'], ['id' => 0, 'name' => 0]),
        ];
    }

    /**
     * Map an export format to the name the plan table uses.
     *
     * config/billing.php lists plain extensions, because that is what an
     * author recognises on a pricing page; the exporter's keys describe the
     * file it builds. Translating here keeps the pricing table readable
     * rather than renaming its entries to match internals.
     */
    private function planFormat(string $format): string
    {
        return match ($format) {
            /*
             * Still 'pdf' here, deliberately.
             *
             * This maps to the ENTITLEMENT key in config/billing.php, where
             * the free plan allows ['pdf', 'epub']. The output is now
             * correctly named print-ready HTML (see BookExport::FORMATS),
             * but changing this line would silently remove the format from
             * the free plan, which is a pricing change rather than a naming
             * fix.
             */
            'pdf_print' => 'pdf',
            // The print kit's files are PDFs, on the plans that have PDF.
            'pdf_interior', 'cover_wrap' => 'pdf',
            default => $format,
        };
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return $bytes < 1048576
            ? round($bytes / 1024).' KB'
            : round($bytes / 1048576, 1).' MB';
    }
}
