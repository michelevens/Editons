<?php

namespace App\Workspace;

use App\Models\Book;
use App\Models\BookWorkspace;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Where an author's workspace layout is kept, and which one applies.
 *
 * users.workspace holds {settings, presets}: the author's layout everywhere,
 * and the layouts they named. A book_workspaces row, when there is one, is
 * the author's layout for that book alone and wins for that book. Both are
 * the author's own: nothing here is shared with a co-author or an editor
 * working on the same book.
 */
final class Workspace
{
    /** What the studio needs on first paint for this person and book. */
    public static function for(User $user, ?Book $book = null): array
    {
        $own = $book ? self::bookRow($user, $book) : null;

        return [
            'settings' => WorkspaceSchema::upgrade($own?->settings ?? self::stored($user)['settings']),
            // 'book' when this book has a layout of its own.
            'scope' => $own ? 'book' : 'user',
            'presets' => self::stored($user)['presets'],
            'defaults' => WorkspaceSchema::defaults(),
        ];
    }

    /**
     * Save a layout (whole or in part) for the author, or for one book.
     *
     * Saving with scope 'user' while the book had its own layout ends the
     * book's: unticking "this book only" means "use my layout here".
     */
    public static function save(User $user, mixed $settings, string $scope = 'user', ?Book $book = null): array
    {
        if ($scope === 'book') {
            $row = self::bookRow($user, $book);
            $base = $row?->settings ?? self::stored($user)['settings'];

            BookWorkspace::updateOrCreate(
                ['user_id' => $user->id, 'book_id' => $book->id],
                ['settings' => WorkspaceSchema::merge($base, $settings)],
            );

            return self::for($user, $book);
        }

        $base = self::stored($user)['settings'];
        $merged = WorkspaceSchema::merge($base, $settings);

        self::write($user, ['settings' => $merged]);

        if ($book) {
            BookWorkspace::where('user_id', $user->id)->where('book_id', $book->id)->delete();
        }

        return self::for($user, $book);
    }

    /** Back to the default layout; named presets are kept. */
    public static function reset(User $user, ?Book $book = null): array
    {
        self::write($user, ['settings' => null]);

        if ($book) {
            BookWorkspace::where('user_id', $user->id)->where('book_id', $book->id)->delete();
        }

        return self::for($user, $book);
    }

    /** Stop using a book's own layout: the author's applies to it again. */
    public static function forget(User $user, Book $book): array
    {
        BookWorkspace::where('user_id', $user->id)->where('book_id', $book->id)->delete();

        return self::for($user, $book);
    }

    /**
     * Name a layout. The same name again replaces it, so "Save as… Evening"
     * twice is one preset, not two.
     *
     * @return array the presets
     */
    public static function addPreset(User $user, string $name, mixed $layout): array
    {
        $name = trim($name);
        $presets = self::stored($user)['presets'];
        $layout = WorkspaceSchema::layoutOf(WorkspaceSchema::merge(null, is_array($layout) ? $layout : [], 'layout'));

        $existing = collect($presets)->search(fn ($p) => mb_strtolower($p['name']) === mb_strtolower($name));

        if ($existing !== false) {
            $presets[$existing]['layout'] = $layout;
            $presets[$existing]['name'] = $name;
        } else {
            if (count($presets) >= WorkspaceSchema::MAX_PRESETS) {
                throw ValidationException::withMessages([
                    'name' => 'You can keep '.WorkspaceSchema::MAX_PRESETS.' layouts. Delete one to save another.',
                ]);
            }

            $presets[] = ['id' => (string) Str::ulid(), 'name' => $name, 'layout' => $layout];
        }

        self::write($user, ['presets' => array_values($presets)]);

        return array_values($presets);
    }

    /** @return array the presets left */
    public static function removePreset(User $user, string $id): array
    {
        $presets = array_values(array_filter(
            self::stored($user)['presets'],
            fn ($p) => $p['id'] !== $id,
        ));

        self::write($user, ['presets' => $presets]);

        return $presets;
    }

    /** users.workspace, its shape guaranteed. */
    private static function stored(User $user): array
    {
        $raw = is_array($user->workspace) ? $user->workspace : [];

        $presets = [];

        foreach ($raw['presets'] ?? [] as $preset) {
            if (! is_array($preset) || ! isset($preset['id'], $preset['name'])) {
                continue;
            }

            $presets[] = [
                'id' => (string) $preset['id'],
                'name' => (string) $preset['name'],
                'layout' => WorkspaceSchema::layoutOf(WorkspaceSchema::upgrade($preset['layout'] ?? [])),
            ];
        }

        return [
            'settings' => is_array($raw['settings'] ?? null) ? $raw['settings'] : null,
            'presets' => $presets,
            // Shortcuts, phone tabs and swipes (App\Workspace\Preferences):
            // carried through untouched, so saving a layout keeps them.
            ...(is_array($raw['prefs'] ?? null) ? ['prefs' => $raw['prefs']] : []),
        ];
    }

    private static function write(User $user, array $changes): void
    {
        $user->forceFill(['workspace' => array_merge(self::stored($user), $changes)])->save();
    }

    private static function bookRow(User $user, ?Book $book): ?BookWorkspace
    {
        return $book
            ? BookWorkspace::where('user_id', $user->id)->where('book_id', $book->id)->first()
            : null;
    }
}
