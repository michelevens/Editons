<?php

namespace App\Shelf;

use App\Models\ShelfView;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Saved shelf views, per person and per shelf (ADR-00XX — Shelf views and
 * bulk actions).
 *
 * Each person has one "current" view per shelf — how they last looked at
 * it, restored when they come back with nothing in the address bar — and
 * up to MAX named ones. Every state is normalised before it is stored, so
 * a saved view can never hold a column or sort the shelf does not know.
 * Only ever the person's own: every lookup is by their user id.
 */
final class ShelfViews
{
    public const MAX = 8;

    /** The state to show: the current view, or the shelf's default. */
    public function current(User $user, string $scope): array
    {
        $saved = ShelfView::where('user_id', $user->id)->where('scope', $scope)->whereNull('name')->value('state');

        return Shelf::normalise(is_array($saved) ? $saved : (is_string($saved) ? (json_decode($saved, true) ?: []) : []), $scope);
    }

    /** Remember how the shelf is being looked at now. */
    public function remember(User $user, string $scope, array $state): array
    {
        $state = Shelf::normalise($state, $scope);

        $view = ShelfView::firstOrNew(['user_id' => $user->id, 'scope' => $scope, 'name' => null]);
        $view->forceFill(['user_id' => $user->id, 'scope' => $scope, 'state' => $state])->save();

        return $state;
    }

    /**
     * The person's named views of this shelf, by name.
     *
     * @return list<array{id: int, name: string, state: array<string, mixed>}>
     */
    public function named(User $user, string $scope): array
    {
        return ShelfView::where('user_id', $user->id)->where('scope', $scope)->whereNotNull('name')
            ->orderByRaw('lower(name)')
            ->get()
            ->map(fn (ShelfView $v) => ['id' => $v->id, 'name' => $v->name, 'state' => Shelf::normalise($v->state ?? [], $scope)])
            ->all();
    }

    /** Save the state under a name; the same name again replaces it. */
    public function save(User $user, string $scope, string $name, array $state): ShelfView
    {
        $name = trim($name);
        $existing = ShelfView::where('user_id', $user->id)->where('scope', $scope)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if (! $existing && ShelfView::where('user_id', $user->id)->where('scope', $scope)->whereNotNull('name')->count() >= self::MAX) {
            throw ValidationException::withMessages(['name' => 'You can keep '.self::MAX.' saved views. Delete one first.']);
        }

        $view = $existing ?? new ShelfView;
        $view->forceFill(['user_id' => $user->id, 'scope' => $scope, 'name' => $name, 'state' => Shelf::normalise($state, $scope)])->save();

        return $view;
    }

    /** Rename one of the person's views, and optionally replace its state. */
    public function update(User $user, int $id, ?string $name, ?array $state): ShelfView
    {
        $view = $this->own($user, $id);

        if ($name !== null) {
            $name = trim($name);
            $clash = ShelfView::where('user_id', $user->id)->where('scope', $view->scope)->whereKeyNot($view->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();

            if ($clash) {
                throw ValidationException::withMessages(['name' => 'You already have a view with that name.']);
            }

            $view->name = $name;
        }

        if ($state !== null) {
            $view->state = Shelf::normalise($state, $view->scope);
        }

        $view->save();

        return $view;
    }

    public function delete(User $user, int $id): void
    {
        $this->own($user, $id)->delete();
    }

    /** One of this person's named views; anybody else's is not found. */
    private function own(User $user, int $id): ShelfView
    {
        return ShelfView::where('user_id', $user->id)->whereNotNull('name')->findOrFail($id);
    }
}
