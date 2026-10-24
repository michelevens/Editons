<?php

namespace App\Platform\Trust;

use App\Inbox\Inbox;
use App\Mail\Notify;
use App\Mail\Trust\ListingRestoredMail;
use App\Mail\Trust\ListingTakenDownMail;
use App\Models\ContentReport;
use App\Models\Product;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Storefront moderation: taking a listing down, putting it back, and the
 * reports readers file from a public page.
 *
 * A takedown hides the public page only. The product row, its orders and
 * the licences buyers hold are untouched: StoreController::download checks
 * the licence and the order, never whether the page is still public, so a
 * reader who paid keeps their copy. The author is emailed (account mail,
 * always delivered) and finds it in their inbox, with the reason.
 */
final class Moderation
{
    public function takeDown(Product $product, string $reason, User $actor, ?ContentReport $report = null): void
    {
        if ($product->isTakenDown()) {
            throw ValidationException::withMessages(['reason' => 'This listing is already taken down.']);
        }

        AdminAudit::transaction(function () use ($product, $reason, $actor, $report) {
            $product->forceFill([
                'taken_down_at' => now(),
                'taken_down_reason' => trim($reason),
                'taken_down_by' => $actor->id,
            ])->save();

            // The report that prompted it is settled by the same act.
            if ($report && $report->status === 'open') {
                $report->forceFill([
                    'status' => 'resolved',
                    'resolution' => 'Listing taken down: '.trim($reason),
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ])->save();
            }

            AdminAudit::record('listing.taken_down', $product, ['taken_down' => false], [
                'taken_down' => true,
                'report_id' => $report?->id,
            ], $reason, $actor);
        });

        $this->tell($product, true, $reason);
    }

    public function restore(Product $product, string $reason, User $actor): void
    {
        if (! $product->isTakenDown()) {
            throw ValidationException::withMessages(['reason' => 'This listing is not taken down.']);
        }

        AdminAudit::transaction(function () use ($product, $reason, $actor) {
            $was = $product->taken_down_reason;

            $product->forceFill(['taken_down_at' => null, 'taken_down_reason' => null, 'taken_down_by' => null])->save();

            AdminAudit::record('listing.restored', $product, ['taken_down' => true, 'taken_down_reason' => $was], ['taken_down' => false], $reason, $actor);
        });

        $this->tell($product, false, $reason);
    }

    /**
     * A report from a public page. No account needed; rate-limited by the
     * route. The reporter's IP is kept only as an HMAC.
     *
     * @param  array{category: string, details?: ?string, email?: ?string}  $data
     */
    public function report(?Product $product, ?string $authorHandle, array $data, Request $request): ContentReport
    {
        return ContentReport::create([
            'product_id' => $product?->id,
            'author_handle' => $authorHandle,
            'category' => $data['category'],
            'details' => filled($data['details'] ?? null) ? trim((string) $data['details']) : null,
            'reporter_email' => filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null,
            'reporter_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            'reporter_user_id' => $request->user()?->id,
            'status' => 'open',
        ]);
    }

    /** Resolve (something was done) or dismiss (nothing needed doing). */
    public function settle(ContentReport $report, string $status, string $resolution, User $actor): void
    {
        if (! in_array($status, ['resolved', 'dismissed'], true)) {
            throw ValidationException::withMessages(['status' => 'Resolve or dismiss.']);
        }

        if ($report->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'This report is already '.$report->status.'.']);
        }

        AdminAudit::transaction(function () use ($report, $status, $resolution, $actor) {
            $report->forceFill([
                'status' => $status,
                'resolution' => trim($resolution),
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            AdminAudit::record('report.'.$status, $report, ['status' => 'open'], ['status' => $status], $resolution, $actor);
        });
    }

    /** Email and inbox, after the change is committed. */
    private function tell(Product $product, bool $down, string $reason): void
    {
        $product->loadMissing('user', 'book');
        $author = $product->user;

        if (! $author || $author->offboarded_at) {
            return;
        }

        $title = $product->displayTitle();

        Notify::send($author, $down
            ? new ListingTakenDownMail($author, $title, trim($reason))
            : new ListingRestoredMail($author, $title, trim($reason)));

        Inbox::record(
            $author,
            'moderation',
            $down
                ? "Your store page for “{$title}” was taken down"
                : "Your store page for “{$title}” is public again",
            $product->book,
            [],
            $down ? trim($reason) : null,
        );
    }
}
