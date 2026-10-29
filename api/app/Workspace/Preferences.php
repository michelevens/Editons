<?php

namespace App\Workspace;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * An author's own keyboard shortcuts, phone tabs and review-deck swipes
 * (ADR — Configurable shortcuts and mobile tabs).
 *
 * Kept in users.workspace beside the writing layout (ADR-0039), under
 * `prefs`: {shortcuts: {id: binding}, mobileTabs: {context: [tab, ...]},
 * reviewDeck: {swipe}}. Only what the author changed is stored; anything
 * absent is the default, so an account that never opened the sheet has
 * exactly the shortcuts and tabs everyone had before.
 *
 * What may be changed, and to what, is read from resources/js/prefs.json,
 * the registry the browser uses too: an unknown id, a shortcut that is not
 * remappable, a binding the browser keeps for itself, or one already taken
 * in the same place is refused with the key's path, as WorkspaceSchema does.
 */
final class Preferences
{
    private const MODIFIERS = ['Mod', 'Alt', 'Shift'];

    /** The key part of a binding: physical codes and named keys. */
    private const KEY = '/^(Key[A-Z]|Digit[0-9]|F([1-9]|1[0-2])|Arrow(Up|Down|Left|Right)|Backslash|Slash|Period|Comma|Semicolon|Quote|BracketLeft|BracketRight|Minus|Equal|Backquote|IntlBackslash|Enter|Escape|Space|Tab|Backspace|Delete|Home|End|PageUp|PageDown|Insert)$/';

    private static ?array $registry = null;

    /** The registry shared with the browser. */
    public static function registry(): array
    {
        return self::$registry ??= json_decode(
            file_get_contents(resource_path('js/prefs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** What the author changed, every section present. */
    public static function for(User $user): array
    {
        $raw = is_array($user->workspace['prefs'] ?? null) ? $user->workspace['prefs'] : [];

        return [
            'shortcuts' => is_array($raw['shortcuts'] ?? null) && $raw['shortcuts'] !== [] ? $raw['shortcuts'] : (object) [],
            'mobileTabs' => is_array($raw['mobileTabs'] ?? null) && $raw['mobileTabs'] !== [] ? $raw['mobileTabs'] : (object) [],
            'reviewDeck' => ['swipe' => $raw['reviewDeck']['swipe'] ?? 'normal'],
        ];
    }

    /**
     * Merge a change over what is stored, validate the whole, save it.
     *
     * A null value puts one shortcut or one context's tabs back to its
     * default (and removes it from storage).
     *
     * @throws ValidationException naming every key that is wrong
     */
    public static function save(User $user, array $input): array
    {
        $errors = [];
        $current = self::for($user);
        $shortcuts = (array) $current['shortcuts'];
        $tabs = (array) $current['mobileTabs'];
        $deck = $current['reviewDeck'];

        foreach (array_keys($input) as $section) {
            if (! in_array($section, ['shortcuts', 'mobileTabs', 'reviewDeck'], true)) {
                $errors[$section] = 'Unknown setting.';
            }
        }

        if (array_key_exists('shortcuts', $input)) {
            $shortcuts = self::mergeShortcuts($shortcuts, $input['shortcuts'], $errors);
        }

        if (array_key_exists('mobileTabs', $input)) {
            $tabs = self::mergeTabs($tabs, $input['mobileTabs'], $errors);
        }

        if (array_key_exists('reviewDeck', $input)) {
            $value = $input['reviewDeck'];

            if (! is_array($value) || array_diff(array_keys($value), ['swipe']) !== []) {
                $errors['reviewDeck'] = 'Only swipe can be set.';
            } elseif (array_key_exists('swipe', $value)) {
                if (! in_array($value['swipe'], self::registry()['swipes'], true)) {
                    $errors['reviewDeck.swipe'] = 'Must be one of: '.implode(', ', self::registry()['swipes']).'.';
                } else {
                    $deck['swipe'] = $value['swipe'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        self::write($user, ['shortcuts' => $shortcuts, 'mobileTabs' => $tabs, 'reviewDeck' => $deck]);

        return self::for($user);
    }

    /** One section back to its defaults. */
    public static function reset(User $user, string $section): array
    {
        $prefs = is_array($user->workspace['prefs'] ?? null) ? $user->workspace['prefs'] : [];
        unset($prefs[$section]);
        self::write($user, $prefs);

        return self::for($user);
    }

    private static function mergeShortcuts(array $stored, mixed $input, array &$errors): array
    {
        if (! is_array($input) || (array_is_list($input) && $input !== [])) {
            $errors['shortcuts'] = 'Must be a map of shortcut ids to keys.';

            return $stored;
        }

        $known = collect(self::registry()['shortcuts'])->keyBy('id');
        $merged = $stored;

        foreach ($input as $id => $binding) {
            $at = "shortcuts.{$id}";
            $entry = $known->get($id);

            if (! $entry) {
                $errors[$at] = 'Unknown shortcut.';

                continue;
            }

            if (! $entry['remap']) {
                $errors[$at] = 'This shortcut cannot be changed.';

                continue;
            }

            if ($binding === null) {
                unset($merged[$id]);

                continue;
            }

            if (! is_string($binding) || ! self::wellFormed($binding)) {
                $errors[$at] = 'Not a key combination.';

                continue;
            }

            if ($reason = self::registry()['reserved'][$binding] ?? null) {
                $errors[$at] = "The browser keeps that one: it {$reason}.";

                continue;
            }

            $scope = self::registry()['scopes'][$entry['scope']];

            if ($scope['typing'] && ! self::hasModifier($binding)) {
                $errors[$at] = 'Here a shortcut needs Ctrl (⌘) or Alt, or it would fire while you type.';

                continue;
            }

            $merged[$id] = $binding;
        }

        // Two shortcuts on one combination in the same place: whichever
        // runs first would hide the other.
        foreach ($input as $id => $binding) {
            if (! is_string($binding) || isset($errors["shortcuts.{$id}"])) {
                continue;
            }

            if ($clash = self::clash($id, $binding, $merged)) {
                $errors["shortcuts.{$id}"] = "Already used by “{$clash['description']}”.";
            }
        }

        return $merged;
    }

    /** The other shortcut on this binding in the same place, if any. */
    private static function clash(string $id, string $binding, array $overrides): ?array
    {
        $shortcuts = collect(self::registry()['shortcuts'])->keyBy('id');
        $scope = $shortcuts[$id]['scope'];

        foreach ($shortcuts as $other) {
            if ($other['id'] === $id) {
                continue;
            }

            // Global shortcuts apply on every screen, so they meet all others.
            $meets = $other['scope'] === $scope || $other['scope'] === 'global' || $scope === 'global';
            $keys = isset($overrides[$other['id']]) ? [$overrides[$other['id']]] : $other['keys'];

            if ($meets && in_array($binding, $keys, true)) {
                return $other;
            }
        }

        return null;
    }

    private static function mergeTabs(array $stored, mixed $input, array &$errors): array
    {
        $contexts = self::registry()['mobileTabs'];

        if (! is_array($input) || (array_is_list($input) && $input !== [])) {
            $errors['mobileTabs'] = 'Must be a map of places to tabs.';

            return $stored;
        }

        foreach ($input as $context => $list) {
            $at = "mobileTabs.{$context}";

            if (! isset($contexts[$context])) {
                $errors[$at] = 'Unknown place.';

                continue;
            }

            if ($list === null) {
                unset($stored[$context]);

                continue;
            }

            $allowed = $contexts[$context]['allowed'] === 'book'
                ? self::registry()['bookTabs']
                : $contexts[$context]['allowed'];
            $max = self::registry()['maxTabs'];

            if (! is_array($list) || ! array_is_list($list) || $list === [] || count($list) > $max) {
                $errors[$at] = "Choose from 1 to {$max} tabs.";

                continue;
            }

            if (count(array_unique($list, SORT_REGULAR)) !== count($list)) {
                $errors[$at] = 'A tab can be pinned once.';

                continue;
            }

            foreach ($list as $tab) {
                if (! is_string($tab) || ! in_array($tab, $allowed, true)) {
                    $errors[$at] = 'Unknown tab: '.(is_string($tab) ? $tab : gettype($tab)).'.';

                    continue 2;
                }
            }

            $stored[$context] = $list;
        }

        return $stored;
    }

    private static function wellFormed(string $binding): bool
    {
        $parts = explode('+', $binding);
        $key = array_pop($parts);

        if (! preg_match(self::KEY, $key)) {
            return false;
        }

        // Modifiers once each, in the canonical order.
        $order = array_values(array_intersect(self::MODIFIERS, $parts));

        return $parts === $order;
    }

    private static function hasModifier(string $binding): bool
    {
        return str_contains($binding, 'Mod+') || str_contains($binding, 'Alt+')
            || (bool) preg_match('/(^|\+)F([1-9]|1[0-2])$/', $binding);
    }

    private static function write(User $user, array $prefs): void
    {
        $workspace = is_array($user->workspace) ? $user->workspace : [];
        $prefs = array_filter($prefs, fn ($v) => $v !== [] && $v !== null && $v !== ['swipe' => 'normal']);

        if ($prefs === []) {
            unset($workspace['prefs']);
        } else {
            $workspace['prefs'] = $prefs;
        }

        $user->forceFill(['workspace' => $workspace])->save();
    }
}
