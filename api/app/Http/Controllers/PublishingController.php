<?php

namespace App\Http\Controllers;

use App\Billing\Entitlements;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\BookExport;
use App\Publishing\Exporter;
use App\Publishing\SubmissionCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            'isbn' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        // The three book columns, then the listing copy that lives in meta.
        foreach (['title', 'author_name', 'list_price', 'isbn'] as $field) {
            if (array_key_exists($field, $data)) {
                $book->{$field} = $data[$field];
            }
        }

        $meta = $book->meta ?? [];

        foreach (['description', 'category', 'keywords'] as $field) {
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
     * Submit to every selected store.
     *
     * Returns 422 with the problems keyed by field when the book is not
     * ready. The screen shows each message against its own input; a flat
     * list would leave the author hunting for the field.
     */
    public function submit(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        if ($errors = $this->check->errors($book)) {
            return response()->json([
                'message' => 'This book is not ready to submit.',
                'errors' => $errors,
            ], 422);
        }

        $pending = $book->channels()->where('status', 'not_submitted')->get();

        if ($pending->isEmpty()) {
            return response()->json([
                'message' => 'Every selected store already has this book.',
                'errors' => ['channels' => 'Nothing new to submit.'],
            ], 422);
        }

        foreach ($pending as $channel) {
            // in_review, not live. A store decides when a book goes on sale,
            // and claiming otherwise would put a date on the screen that
            // never happened.
            $channel->forceFill([
                'status' => 'in_review',
                'submitted_at' => now(),
            ])->save();
        }

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

        // submitted_at is kept: it is a record of what happened, and the
        // CHECK constraint requires it for any status but not_submitted.
        $channel->forceFill([
            'status' => 'unpublished',
            'went_live_at' => null,
        ])->save();

        return response()->json($this->payload($book->fresh()));
    }

    /** Generate a file. */
    public function generate(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'format' => ['required', 'string', 'in:'.implode(',', array_keys(BookExport::FORMATS))],
        ]);

        // The plan decides which formats are available. The method existed
        // and nothing called it, so every plan could export everything.
        $this->entitlements->assertCanExport($request->user(), $this->planFormat($data['format']));

        try {
            $export = $this->exporter->generate($book, $data['format'], $request->user()->id);
        } catch (Throwable $e) {
            // 422 rather than 500: an empty manuscript is the author's to
            // fix, and the message says what to do about it.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'export' => $this->exportRow($export),
            'book' => $this->payload($book->fresh()),
        ]);
    }

    /**
     * Download a generated file.
     *
     * Served through here rather than from a public URL: these are unpublished
     * manuscripts, and a guessable path would hand one to anybody.
     */
    public function download(Book $book, BookExport $export): StreamedResponse
    {
        $this->authorize('view', $book);

        abort_unless($export->book_id === $book->id, 404);
        abort_unless($export->status === 'ready' && $export->path, 404);
        abort_unless(Storage::disk('local')->exists($export->path), 404, 'That file is no longer on disk.');

        return Storage::disk('local')->download(
            $export->path,
            basename($export->path),
        );
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
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
                'isbn' => $book->isbn,
                'word_count' => $book->word_count,
                'status' => $book->status,
            ],
            'categories' => self::CATEGORIES,
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
            'word_count' => $export->word_count,
            'size' => $export->byte_size ? $this->humanSize($export->byte_size) : null,
            'created_at' => $export->created_at->diffForHumans(),
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

    /**
     * Store categories.
     *
     * A fixed list rather than free text: stores match against their own
     * taxonomy, and a typed category is a rejected submission.
     */
    private const CATEGORIES = [
        'Business & Economics / Small Business',
        'Business & Economics / Entrepreneurship',
        'Medical / Practice Management',
        'Medical / Nursing / Practice Management',
        'Self-Help / Personal Growth',
        'Education / Professional Development',
        'Fiction / Literary',
        'Biography & Autobiography / Personal Memoirs',
    ];
}
