<?php

use App\I18n\Locales;

/*
 * The catalogues themselves (lang/{en,fr,ht}.json): every interface key the
 * screens use exists in English, French and Kreyòl have every English key
 * (or are explicitly allowed to fall back), parameters match, and the
 * priority emails are translated.
 */

/** Interface keys French or Kreyòl may leave to the English fallback. */
const I18N_ALLOWED_FALLBACK = [
    'fr' => [],
    'ht' => [],
];

function rawCatalogue(string $locale): array
{
    return json_decode(file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
}

function interfaceKeys(array $catalogue): array
{
    return array_values(array_filter(array_keys($catalogue), fn ($key) => Locales::isInterfaceKey($key)));
}

/** {name}, and the argument names of {count, plural, ...}. */
function icuParams(string $message): array
{
    preg_match_all('/\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:[,}])/', $message, $m);

    return array_values(array_unique($m[1]));
}

/** Every key the JSX names literally: t('…') calls and tKey: '…' entries. */
function jsxKeys(): array
{
    $keys = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));

    foreach ($files as $file) {
        if (! preg_match('/\.(jsx?|tsx?)$/', $file->getFilename())) {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('js')) + 1));

        preg_match_all("/(?<![\\w.])t\\(\\s*'([a-z0-9_]+(?:\\.[a-zA-Z0-9_]+)+)'/", $source, $calls);
        preg_match_all("/\b(?:tKey|blurbKey):\\s*'([^']+)'/", $source, $entries);

        foreach (array_merge($calls[1], $entries[1]) as $key) {
            $keys[$key][] = $relative;
        }
    }

    return $keys;
}

it('has every key the screens use in en.json (static scan of the JSX)', function () {
    $en = rawCatalogue('en');
    $used = jsxKeys();

    $missing = array_diff_key($used, $en);

    expect($used)->not->toBeEmpty()
        ->and(array_map(fn ($files) => implode(', ', array_unique($files)), $missing))->toBe([]);
});

it('gives every shortcut and scope of prefs.json an English key in its own words', function () {
    $en = rawCatalogue('en');
    $registry = json_decode(file_get_contents(resource_path('js/prefs.json')), true);

    foreach ($registry['shortcuts'] as $shortcut) {
        expect($en["shortcut.{$shortcut['id']}"] ?? null)->toBe($shortcut['description']);
    }

    foreach ($registry['scopes'] as $scope => $meta) {
        expect($en["shortcut_scope.{$scope}"] ?? null)->toBe($meta['label']);
    }

    foreach ($registry['reserved'] as $binding => $does) {
        expect($en['shortcut_reserved.'.str_replace('+', '_', $binding)] ?? null)->toBe($does);
    }

    foreach ($registry['mobileTabs'] as $context => $meta) {
        expect($en["tab_context.{$context}"] ?? null)->toBe($meta['label']);
    }
});

it('has every English key in French and Kreyòl, and reports the coverage', function (string $locale) {
    $en = interfaceKeys(rawCatalogue('en'));
    $other = rawCatalogue($locale);

    $missing = array_values(array_diff($en, array_keys($other), I18N_ALLOWED_FALLBACK[$locale]));
    $covered = count(array_intersect($en, array_keys($other)));
    $coverage = round($covered / max(1, count($en)) * 100, 1);

    fwrite(STDOUT, sprintf("\n  [i18n] %s: %d of %d interface keys (%.1f%%)\n", $locale, $covered, count($en), $coverage));

    expect($missing)->toBe([])
        // Nothing in the other language that English does not have.
        ->and(array_values(array_diff(interfaceKeys($other), $en)))->toBe([]);
})->with(['fr', 'ht']);

it('keeps the same parameters in every translation', function (string $locale) {
    $en = rawCatalogue('en');
    $other = rawCatalogue($locale);
    $wrong = [];

    foreach (interfaceKeys($en) as $key) {
        if (! isset($other[$key])) {
            continue;
        }

        $mine = icuParams($other[$key]);
        $english = icuParams($en[$key]);
        // A plural's selector may go (Kreyòl rarely needs the branch);
        // every other parameter must stay, and none may be invented.
        preg_match_all('/\{\s*([a-zA-Z_]\w*)\s*,\s*(?:plural|select)\b/', $en[$key], $selectors);

        if (array_diff($mine, $english) !== [] || array_diff($english, $mine, $selectors[1]) !== []) {
            $wrong[$key] = $other[$key];
        }
    }

    // Sentence keys (email copy): :name parameters, the English is the key.
    foreach ($other as $key => $value) {
        if ($key === '_meta' || Locales::isInterfaceKey($key)) {
            continue;
        }

        preg_match_all('/:([a-z_]+)/', $key, $a);
        preg_match_all('/:([a-z_]+)/', (string) $value, $b);

        // A translation may leave a parameter out (French has no article
        // to agree), never invent one.
        if (array_diff($b[1], $a[1]) !== []) {
            $wrong[$key] = $value;
        }
    }

    expect($wrong)->toBe([]);
})->with(['fr', 'ht']);

it('never uses a key Laravel reads from its own lang files', function () {
    // __('auth.password') is the framework's "The provided password is
    // incorrect."; a JSON key with that name would replace it.
    $clash = array_filter(
        interfaceKeys(rawCatalogue('en')),
        fn ($key) => preg_match('/^(auth|passwords|pagination|validation)\./', $key),
    );

    expect($clash)->toBe([]);
});

it('marks the Kreyòl catalogue as a draft for native review', function () {
    expect(rawCatalogue('ht')['_meta']['status'])->toBe('draft — needs native review')
        ->and(Locales::meta('ht')['status'])->toBe('draft — needs native review');
});

it('translates every string of the priority emails', function (string $locale) {
    $files = [
        'Mail/EcrivainMail.php', 'Mail/Account/PasswordResetMail.php', 'Mail/Account/VerifyEmailMail.php',
        'Mail/Collaboration/BookInvitationMail.php', 'Mail/Commerce/RoyaltyStatementMail.php',
        'Mail/Writing/WeeklyDigestMail.php',
    ];
    $views = glob(resource_path('views/emails/{,*/}*.blade.php'), GLOB_BRACE);
    $catalogue = rawCatalogue($locale);
    $missing = [];

    foreach ([...array_map('app_path', $files), ...$views] as $path) {
        preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($path), $m);

        foreach ($m[1] as $string) {
            $string = str_replace("\\'", "'", $string);

            if (! isset($catalogue[$string])) {
                $missing[] = $string;
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
})->with(['fr', 'ht']);

it('has the Laravel message files for French and Kreyòl', function (string $locale) {
    foreach (['validation', 'auth', 'passwords', 'pagination'] as $group) {
        $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$group}.php");
        $own = require lang_path("{$locale}/{$group}.php");

        $flat = fn (array $lines) => array_keys(Illuminate\Support\Arr::dot(Illuminate\Support\Arr::except($lines, ['custom', 'attributes'])));

        expect(array_values(array_diff($flat($english), $flat($own))))->toBe([]);
    }
})->with(['fr', 'ht']);
