<?php

namespace App\I18n;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The interface languages (ADR — Interface languages: English, French,
 * Haitian Creole).
 *
 * One catalogue per language, lang/{en,fr,ht}.json, shared by Laravel and
 * the browser. Two kinds of key live in it:
 *
 * - interface keys, dotted ("nav.books", "login.title"), written in the ICU
 *   subset of MessageFormat and read with Locales::t() here or t() in
 *   resources/js/i18n.js. en.json holds their English, verbatim from what
 *   the screens said before; a key missing from fr or ht falls back to it.
 * - sentence keys, the English itself ("Reset password"), which is what the
 *   email copy has always passed to __() with :name parameters. These are
 *   only in fr.json and ht.json; English needs no entry.
 *
 * `_meta` describes the file (review status) and is never a string.
 */
final class Locales
{
    public const SUPPORTED = ['en', 'fr', 'ht'];

    public const DEFAULT = 'en';

    /** What each language is called in itself, for the switcher. */
    public const NAMES = [
        'en' => 'English',
        'fr' => 'Français',
        'ht' => 'Kreyòl ayisyen',
    ];

    /** @var array<string, array<string, string>> */
    private static array $catalogues = [];

    /** @var array<string, true> */
    private static array $reported = [];

    public static function supported(?string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    /**
     * The BCP 47 tag Intl formats numbers and dates with.
     *
     * There is no CLDR data for Haitian Creole, so numbers follow Haiti's
     * French conventions (fr-HT); month and day names come from the
     * catalogue in the browser.
     */
    public static function intl(string $locale): string
    {
        return match ($locale) {
            'fr' => 'fr-FR',
            'ht' => 'fr-HT',
            default => 'en-US',
        };
    }

    /**
     * The language a request should get: the account's, else the one chosen
     * on the sign-in page this session, else the browser's.
     */
    public static function forRequest(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User && self::supported($user->locale)) {
            return $user->locale;
        }

        if ($request->hasSession() && self::supported($request->session()->get('locale'))) {
            return $request->session()->get('locale');
        }

        return self::negotiate($request->header('Accept-Language'));
    }

    /**
     * The best supported language for an Accept-Language header.
     *
     * "fr-CA,fr;q=0.9,en;q=0.8" is French. Haitian Creole is "ht" (some
     * phones send "ht-HT"); anything unsupported, or no header, is English.
     */
    public static function negotiate(?string $header): string
    {
        if (! $header) {
            return self::DEFAULT;
        }

        $ranked = [];

        foreach (explode(',', $header) as $index => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            $q = 1.0;

            foreach (array_slice($pieces, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $q = (float) substr(trim($param), 2);
                }
            }

            $base = explode('-', str_replace('_', '-', $tag))[0];

            if ($q > 0 && self::supported($base)) {
                // Earlier wins between equal weights, as the header intends.
                $ranked[] = [$q, -$index, $base];
            }
        }

        if ($ranked === []) {
            return self::DEFAULT;
        }

        rsort($ranked);

        return $ranked[0][2];
    }

    /**
     * A language's strings, keyed, without `_meta`.
     *
     * @return array<string, string>
     */
    public static function catalogue(string $locale): array
    {
        if (! self::supported($locale)) {
            $locale = self::DEFAULT;
        }

        return self::$catalogues[$locale] ??= self::load($locale);
    }

    /** The file's `_meta` block (review status). */
    public static function meta(string $locale): array
    {
        $path = lang_path("{$locale}.json");
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($raw['_meta'] ?? null) ? $raw['_meta'] : [];
    }

    /**
     * What the browser needs for a language: its interface strings. English
     * sends nothing: the bundle carries en.json as the fallback, so English
     * pages are exactly what they were, byte for byte and on the wire.
     *
     * @return array<string, string>
     */
    public static function messagesFor(string $locale): array
    {
        if ($locale === self::DEFAULT || ! self::supported($locale)) {
            return [];
        }

        return array_filter(
            self::catalogue($locale),
            fn ($value, $key) => self::isInterfaceKey((string) $key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Changes when the catalogue file does, so the browser refetches it. */
    public static function version(string $locale): string
    {
        $path = lang_path("{$locale}.json");

        return substr(md5($locale.'|'.(is_file($path) ? filemtime($path).'-'.filesize($path) : '')), 0, 10);
    }

    /** "nav.books" is an interface key; "Reset password" is a sentence key. */
    public static function isInterfaceKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z0-9_]+(\.[a-zA-Z0-9_]+)+$/', $key);
    }

    /**
     * An interface string in the current (or given) language.
     *
     * Missing from that language: the English. Missing from English too:
     * the key's last part, humanised (never the raw key in front of an
     * author) and, outside production, a log line naming it.
     */
    public static function t(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = self::supported($locale) ? $locale : app()->getLocale();
        $message = self::catalogue($locale)[$key] ?? null;

        if ($message === null && $locale !== self::DEFAULT) {
            self::reportMissing($key, $locale);
            $message = self::catalogue(self::DEFAULT)[$key] ?? null;
        }

        if ($message === null) {
            self::reportMissing($key, self::DEFAULT);
            $parts = explode('.', $key);
            $message = ucfirst(str_replace('_', ' ', (string) end($parts)));
        }

        return MessageFormat::format($message, $params, $locale);
    }

    private static function load(string $locale): array
    {
        $path = lang_path("{$locale}.json");
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        if (! is_array($raw)) {
            return [];
        }

        unset($raw['_meta']);

        return array_filter($raw, 'is_string');
    }

    private static function reportMissing(string $key, string $locale): void
    {
        if (app()->isProduction() || isset(self::$reported[$locale.'|'.$key])) {
            return;
        }

        self::$reported[$locale.'|'.$key] = true;
        Log::debug('Missing translation', ['key' => $key, 'locale' => $locale]);
    }

    /** Forget the loaded catalogues (tests that change them). */
    public static function flush(): void
    {
        self::$catalogues = [];
        self::$reported = [];
    }
}
