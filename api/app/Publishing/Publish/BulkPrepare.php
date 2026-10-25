<?php

namespace App\Publishing\Publish;

use App\Models\Book;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Prepare for publishing" across several books: the author's shelf, or
 * the console's House catalog.
 *
 * Step 1 (PublishCheck) for each book, the stale files regenerated
 * (Regenerate), and links to one combined Google Play book list and zip
 * and one KDP checklist with a guided page per book, in the order given.
 * Each book stands on its own: one that cannot regenerate does not stop
 * the next. From the console, each book's run is written to the admin
 * audit log with the reason given.
 */
class BulkPrepare
{
    public function __construct(
        private readonly PublishCheck $check,
        private readonly Regenerate $regenerate,
    ) {}

    /**
     * @param  Collection<int, Book>  $books  in the order to report them
     * @return list<array<string, mixed>>
     */
    public function run(Collection $books, User $actor, ?string $auditReason = null): array
    {
        $rows = [];

        foreach ($books as $book) {
            try {
                $files = $this->regenerate->stale($book, $actor);
                $check = $this->check->run($book->fresh());

                if ($auditReason !== null) {
                    AdminAudit::transaction(fn () => AdminAudit::record('house.publish_prepared', $book, null, [
                        'status' => $check['status'],
                        'counts' => $check['counts'],
                        'regenerated' => array_map(fn ($r) => $r['format'].($r['service'] ? ':'.$r['service'] : '').'='.$r['result'], $files),
                    ], $auditReason, $actor));
                }

                $rows[] = [
                    'id' => $book->id,
                    'title' => $book->title,
                    'ok' => true,
                    'status' => $check['status'],
                    'summary' => $check['summary'],
                    'counts' => $check['counts'],
                    'problems' => collect($check['items'])->where('status', 'fail')->pluck('message')->take(5)->values()->all(),
                    'regenerated' => $files,
                    'chosen' => PublishPlan::chosen($book),
                    'message' => $check['summary'].($files ? ' Regenerating '.count($files).' file'.(count($files) === 1 ? '' : 's').'.' : ''),
                ];
            } catch (Throwable $e) {
                report($e);
                $rows[] = ['id' => $book->id, 'title' => $book->title, 'ok' => false, 'status' => 'red', 'message' => $e->getMessage()];
            }
        }

        return $rows;
    }
}
