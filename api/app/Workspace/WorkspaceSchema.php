<?php

namespace App\Workspace;

use Illuminate\Validation\ValidationException;

/**
 * The writing workspace an author has arranged (ADR — customisable writing
 * workspace): which studio panels show, how wide they are, which side they
 * sit on, and how the writing canvas reads.
 *
 * One schema, in one place, because the settings come from the browser and
 * are stored as JSON: every key is named here with its type and bounds, and
 * a key that is not is refused rather than kept. A JSON column that takes
 * whatever it is sent becomes a place to park anything, and the editor reads
 * it on every page load.
 *
 * Settings are always stored whole. A request may send part of them; it is
 * merged over what was there (or the defaults) and the result is validated
 * as a whole, so a stored layout is never half-formed.
 *
 * None of this touches the book: the canvas font and width are how the
 * author sees the manuscript while writing, not its design or its exports.
 */
final class WorkspaceSchema
{
    /** Bumped when the shape changes; stored settings carry it. */
    public const VERSION = 1;

    /** How many named layouts one person can keep. */
    public const MAX_PRESETS = 12;

    public const PRESET_NAME_MAX = 40;

    /**
     * The shape: a list is an enum, ['int', min, max] / ['float', min, max]
     * a bounded number, 'bool' a switch, an array of keys a nested group.
     */
    private const SHAPE = [
        'version' => ['int', 1, 1],
        // Regions of the studio. Hidden, not unmounted: see the ADR.
        'panels' => [
            'topNav' => 'bool',
            'sidebar' => 'bool',
            'credits' => 'bool',
            'outline' => 'bool',
            'right' => 'bool',
            'bottom' => 'bool',
            'toolbar' => 'bool',
            'statusBar' => 'bool',
        ],
        // Parts inside those regions.
        'subpanels' => [
            'outlineTabs' => 'bool',
            'outlineTools' => 'bool',
            'outlineGoals' => 'bool',
            'rightTabs' => [
                'editor' => 'bool',
                'comments' => 'bool',
                'research' => 'bool',
                'checks' => 'bool',
            ],
            'bottomCards' => [
                'research' => 'bool',
                'design' => 'bool',
                'cover' => 'bool',
                'export' => 'bool',
            ],
        ],
        // Pixel sizes of the resizable panels.
        'sizes' => [
            'outline' => ['int', 200, 480],
            'right' => ['int', 300, 640],
            'bottom' => ['int', 120, 480],
        ],
        // Collapsed to a strip of icons.
        'rails' => [
            'outline' => 'bool',
            'right' => 'bool',
        ],
        // The outline on the right and the AI panel on the left.
        'swap' => 'bool',
        'canvas' => [
            'width' => ['narrow', 'medium', 'wide', 'full', 'custom'],
            'customWidth' => ['int', 400, 1600],
            'font' => ['serif', 'sans', 'georgia', 'mono'],
            'fontSize' => ['int', 13, 28],
            'lineHeight' => ['float', 1.1, 2.4],
            'background' => ['plain', 'paper'],
            'toolbarStyle' => ['docked', 'floating'],
            'typewriter' => 'bool',
            'pageView' => 'bool',
        ],
        // The preset last applied, if the layout is still that preset.
        'preset' => 'string?',
    ];

    /** The studio as it was before any of this: everything shown. */
    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            'panels' => [
                'topNav' => true,
                'sidebar' => true,
                'credits' => true,
                'outline' => true,
                'right' => true,
                'bottom' => true,
                'toolbar' => true,
                'statusBar' => true,
            ],
            'subpanels' => [
                'outlineTabs' => true,
                'outlineTools' => true,
                'outlineGoals' => true,
                'rightTabs' => ['editor' => true, 'comments' => true, 'research' => true, 'checks' => true],
                'bottomCards' => ['research' => true, 'design' => true, 'cover' => true, 'export' => true],
            ],
            'sizes' => ['outline' => 268, 'right' => 392, 'bottom' => 236],
            'rails' => ['outline' => false, 'right' => false],
            'swap' => false,
            'canvas' => [
                'width' => 'full',
                'customWidth' => 760,
                'font' => 'serif',
                'fontSize' => 17,
                'lineHeight' => 1.41,
                'background' => 'plain',
                'toolbarStyle' => 'docked',
                'typewriter' => false,
                'pageView' => false,
            ],
            'preset' => null,
        ];
    }

    /**
     * Merge a partial layout over a base and validate the whole.
     *
     * @param  mixed  $input  what the request sent
     * @param  string  $field  the request field, for error keys
     *
     * @throws ValidationException naming every key that is unknown or wrong
     */
    public static function merge(?array $base, mixed $input, string $field = 'settings'): array
    {
        $errors = [];

        if (! is_array($input) || array_is_list($input) && $input !== []) {
            throw ValidationException::withMessages([$field => 'The workspace settings must be an object.']);
        }

        $merged = self::walk(self::SHAPE, self::upgrade($base), $input, $field, $errors);

        if ($errors === [] && ! in_array(true, $merged['subpanels']['rightTabs'], true)) {
            $errors["{$field}.subpanels.rightTabs"] = 'Keep at least one tab in the right panel, or hide the panel.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $merged['version'] = self::VERSION;

        return $merged;
    }

    /** A stored layout read back: anything missing (a newer key) defaulted. */
    public static function upgrade(?array $stored): array
    {
        $defaults = self::defaults();

        if (! $stored) {
            return $defaults;
        }

        // Stored settings passed validation when written; only fill gaps,
        // and drop keys an older schema had that this one does not.
        return self::fill(self::SHAPE, $defaults, $stored);
    }

    /** The parts of a layout a preset holds: everything but its own name. */
    public static function layoutOf(array $settings): array
    {
        unset($settings['preset'], $settings['version']);

        return $settings;
    }

    private static function walk(array $shape, array $base, array $input, string $path, array &$errors): array
    {
        foreach ($input as $key => $value) {
            $at = "{$path}.{$key}";

            if (! is_string($key) || ! array_key_exists($key, $shape)) {
                $errors[$at] = 'Unknown setting.';

                continue;
            }

            $rule = $shape[$key];

            if (is_array($rule) && ! array_is_list($rule)) {
                if (! is_array($value) || (array_is_list($value) && $value !== [])) {
                    $errors[$at] = 'Must be a group of settings.';

                    continue;
                }

                $base[$key] = self::walk($rule, $base[$key], $value, $at, $errors);

                continue;
            }

            $checked = self::check($rule, $value);

            if ($checked === null && $rule !== 'string?') {
                $errors[$at] = self::describe($rule);

                continue;
            }

            if ($rule === 'string?' && $value !== null && $checked === null) {
                $errors[$at] = self::describe($rule);

                continue;
            }

            $base[$key] = $checked;
        }

        return $base;
    }

    /** The value as stored, or null when it does not fit the rule. */
    private static function check(string|array $rule, mixed $value): mixed
    {
        if ($rule === 'bool') {
            return is_bool($value) ? $value : null;
        }

        if ($rule === 'string?') {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);

            return $value !== '' && mb_strlen($value) <= self::PRESET_NAME_MAX ? $value : null;
        }

        if (in_array($rule[0], ['int', 'float'], true) && count($rule) === 3) {
            [$type, $min, $max] = $rule;

            if ($type === 'int' && ! is_int($value)) {
                return null;
            }

            if ($type === 'float' && ! is_int($value) && ! is_float($value)) {
                return null;
            }

            if ($value < $min || $value > $max) {
                return null;
            }

            return $type === 'float' ? round((float) $value, 2) : $value;
        }

        return in_array($value, $rule, true) ? $value : null;
    }

    private static function describe(string|array $rule): string
    {
        return match (true) {
            $rule === 'bool' => 'Must be true or false.',
            $rule === 'string?' => 'Must be a name of up to '.self::PRESET_NAME_MAX.' characters, or null.',
            in_array($rule[0], ['int', 'float'], true) && count($rule) === 3 => "Must be a number from {$rule[1]} to {$rule[2]}.",
            default => 'Must be one of: '.implode(', ', $rule).'.',
        };
    }

    private static function fill(array $shape, array $defaults, array $stored): array
    {
        $out = $defaults;

        foreach ($shape as $key => $rule) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            if (is_array($rule) && ! array_is_list($rule)) {
                $out[$key] = is_array($stored[$key]) ? self::fill($rule, $defaults[$key], $stored[$key]) : $defaults[$key];

                continue;
            }

            $checked = self::check($rule, $stored[$key]);
            $out[$key] = $checked ?? $defaults[$key];
        }

        return $out;
    }
}
