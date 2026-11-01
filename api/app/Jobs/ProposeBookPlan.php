<?php

namespace App\Jobs;

use App\Ai\BookDrafter;
use App\Models\Book;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ask the AI writer for a book's plan, on the worker (ADR — AI writes the
 * book). The studio polls the book's draft state until the plan is there.
 */
class ProposeBookPlan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $bookId, public readonly int $userId) {}

    public function handle(BookDrafter $drafter): void
    {
        $book = Book::find($this->bookId);
        $user = User::find($this->userId);

        if ($book && $user && ($book->ai_plan['status'] ?? null) === 'proposing') {
            $drafter->plan($book, $user);
        }
    }

    public function failed(?Throwable $e): void
    {
        $book = Book::find($this->bookId);

        if ($book && ($book->ai_plan['status'] ?? null) === 'proposing') {
            $book->forceFill(['ai_plan' => array_merge($book->ai_plan, ['status' => 'failed', 'error' => __('Planning stopped. Nothing was charged; try again.')])])->save();
        }
    }
}
