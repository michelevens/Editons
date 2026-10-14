<?php

namespace App\Http\Controllers;

use App\Models\AdvanceReader;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Launch Planner.
 *
 * The timeline is computed, not stored: each task sits at a fixed offset from
 * the launch date, so moving the date moves everything with it. Storing eight
 * dated rows per book would be storing a calculation and then having to keep
 * it in step.
 *
 * Nothing here sends email. Marking a copy as sent records that the author
 * sent one; the application does not pretend to have delivered it.
 */
class LaunchController extends Controller
{
    /**
     * The launch timeline: [days from launch, key, label, what it means].
     *
     * Negative is before launch day. The order is the order they are done in,
     * which is also date order as long as the offsets stay sorted.
     */
    private const TASKS = [
        [-60, 'recruit_readers', 'Recruit advance readers', 'Reviews on launch day come from people who read it before.'],
        [-45, 'send_copies', 'Send advance copies', 'An EPUB is enough; most readers do not need print.'],
        [-30, 'set_up_preorder', 'Set up the pre-order', 'Pre-orders all count as first-day sales at most stores.'],
        [-14, 'announce', 'Announce the date', 'Tell the people who already know you before you tell anyone else.'],
        [-7, 'schedule_posts', 'Schedule launch-week posts', 'Written in advance, because launch week is busy.'],
        [0, 'publish', 'Launch day: publish to stores', 'Submit from Export & Publish, if you have not already.'],
        [7, 'ask_for_reviews', 'Ask advance readers for reviews', 'A week in is when a reminder is still welcome.'],
        [30, 'run_promotion', 'Run a price promotion', 'A month in, when the launch spike has settled.'],
    ];

    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($book));
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'launch_date' => ['sometimes', 'nullable', 'date'],
            'task' => ['sometimes', 'string', Rule::in(array_column(self::TASKS, 1))],
            'done' => ['required_with:task', 'boolean'],
        ]);

        if (array_key_exists('launch_date', $data)) {
            $book->launch_date = $data['launch_date'];
        }

        if (isset($data['task'])) {
            $tasks = $book->launch_tasks ?? [];
            $tasks[$data['task']] = (bool) $data['done'];
            $book->launch_tasks = $tasks;
        }

        $book->save();

        return response()->json($this->payload($book->fresh()));
    }

    public function addReader(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($book->advanceReaders()->where('email', $data['email'])->exists()) {
            return response()->json([
                'message' => 'That reader is already on the list.',
            ], 422);
        }

        $book->advanceReaders()->create($data);

        return response()->json($this->payload($book->fresh()), 201);
    }

    public function updateReader(Request $request, Book $book, AdvanceReader $reader): JsonResponse
    {
        $this->authorize('update', $book);

        abort_unless($reader->book_id === $book->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(AdvanceReader::STATUSES)],
        ]);

        // The timestamps go with the status, set here rather than accepted
        // from the client: when a copy went out is a record of what happened.
        $attributes = ['status' => $data['status']];

        if ($data['status'] === 'sent' && ! $reader->copy_sent_at) {
            $attributes['copy_sent_at'] = now();
        }

        if ($data['status'] === 'reviewed') {
            $attributes['reviewed_at'] = now();
            // A review implies a copy. The database enforces this, and
            // without it marking somebody reviewed straight from invited
            // would be rejected rather than corrected.
            $attributes['copy_sent_at'] = $reader->copy_sent_at ?? now();
        }

        $reader->forceFill($attributes)->save();

        return response()->json($this->payload($book->fresh()));
    }

    public function removeReader(Book $book, AdvanceReader $reader): JsonResponse
    {
        $this->authorize('update', $book);

        abort_unless($reader->book_id === $book->id, 404);

        $reader->delete();

        return response()->json($this->payload($book->fresh()));
    }

    /** @return array<string, mixed> */
    private function payload(Book $book): array
    {
        $launch = $book->launch_date;
        $done = $book->launch_tasks ?? [];
        $readers = $book->advanceReaders()->orderBy('name')->get();

        return [
            'launch_date' => $launch?->toDateString(),
            'days_away' => $launch ? (int) now()->startOfDay()->diffInDays($launch, false) : null,
            'tasks' => collect(self::TASKS)
                ->map(function (array $task) use ($launch, $done) {
                    [$offset, $key, $label, $detail] = $task;

                    $due = $launch?->copy()->addDays($offset);
                    $isDone = (bool) ($done[$key] ?? false);

                    return [
                        'key' => $key,
                        'label' => $label,
                        'detail' => $detail,
                        'offset' => $offset,
                        'due' => $due?->toFormattedDateString(),
                        'done' => $isDone,
                        // Only meaningful once a date is set, and never for
                        // something already finished.
                        'overdue' => ! $isDone && $due !== null && $due->isPast(),
                    ];
                })
                ->all(),
            'done_count' => count(array_filter($done)),
            'task_count' => count(self::TASKS),
            'readers' => $readers
                ->map(fn (AdvanceReader $reader) => [
                    'id' => $reader->id,
                    'name' => $reader->name,
                    'email' => $reader->email,
                    'status' => $reader->status,
                    'copy_sent_at' => $reader->copy_sent_at?->toFormattedDateString(),
                    'reviewed_at' => $reader->reviewed_at?->toFormattedDateString(),
                ])
                ->all(),
            'reviews' => $readers->where('status', 'reviewed')->count(),
            'statuses' => AdvanceReader::STATUSES,
        ];
    }
}
