<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\BookTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Moving an account's books to another account, from Accounts › one account
 * (ADR — moving books between any accounts).
 *
 * The owner's only (routes/admin.php): find the target, preview — the same
 * plan `books:transfer` prints, nothing written — then apply with a reason,
 * all or nothing, audited per book by BookTransfer.
 */
class BookTransferController extends Controller
{
    /** Accounts the books could go to, by email, name, house slug or id. */
    public function targets(Request $request, User $user): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2 && ! ctype_digit($q)) {
            return response()->json(['accounts' => []]);
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

        $accounts = User::query()
            ->whereKeyNot($user->id)
            ->whereNull('suspended_at')
            ->where(function ($w) use ($q, $like) {
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q);
                }

                $w->orWhere('email', 'ilike', $like)->orWhere('name', 'ilike', $like)->orWhere('house_slug', 'ilike', $like);
            })
            ->orderByRaw("case when account_kind = 'house' then 0 else 1 end")
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email', 'account_kind', 'house_slug']);

        return response()->json(['accounts' => $accounts->map(fn (User $a) => [
            'id' => $a->id,
            'name' => $a->name,
            // A house account's address is a reserved one nobody reads.
            'email' => $a->isHouse() ? null : $a->email,
            'house' => $a->isHouse(),
            'slug' => $a->house_slug,
        ])->values()]);
    }

    /** The dry run: what would move, and what would stop it. */
    public function preview(Request $request, User $user, BookTransfer $transfer): JsonResponse
    {
        $data = $this->input($request);

        return response()->json($transfer->plan($data['books'], $data['to'], $data['keep_previous'], $user));
    }

    public function transfer(Request $request, User $user, BookTransfer $transfer): RedirectResponse
    {
        $data = $this->input($request, reason: true);
        $plan = $transfer->apply($data['books'], $data['to'], $data['reason'], $request->user(), $data['keep_previous'], $user);

        return back()->with('platform_notice', count($plan['books']).' book(s) moved to '.$plan['to']['name'].'.');
    }

    /** @return array{books: list<int>, to: User, keep_previous: ?bool, reason: string} */
    private function input(Request $request, bool $reason = false): array
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:200'],
            'books.*' => ['integer'],
            'to' => ['required', 'integer', 'exists:users,id'],
            'keep_previous' => ['sometimes', 'nullable', 'boolean'],
            'reason' => [$reason ? 'required' : 'nullable', 'string', 'min:4', 'max:1000'],
        ]);

        return [
            'books' => array_map('intval', $data['books']),
            'to' => User::findOrFail((int) $data['to']),
            'keep_previous' => isset($data['keep_previous']) ? (bool) $data['keep_previous'] : null,
            'reason' => $data['reason'] ?? '',
        ];
    }
}
