<?php

namespace App\Commerce\Print;

use App\Models\Book;

/**
 * The Lulu product code (pod_package_id) for a book's paperback.
 *
 * Built from the book's own print settings — trim (Design), ink, paper and
 * cover finish (Print & price) — in Lulu's dotted form,
 * "[Trim].[Ink].[Quality].[Binding].[Paper].[Finish]", with the parts in
 * config/publishing.php (lulu_packages). A combination Lulu does not make
 * is caught by Lulu's own file validation, which receives this code.
 */
class LuluPackage
{
    /** The code, or null with the reason when the settings have no Lulu equivalent. */
    public static function for(Book $book): array
    {
        $parts = config('publishing.lulu_packages');
        $trim = $book->layout()['trim'];
        $print = $book->printSettings();

        $trimCode = $parts['trims'][$trim] ?? null;

        if ($trimCode === null) {
            return ['id' => null, 'problem' => "Lulu's codes here do not include the {$trim} trim. Choose another trim in Design."];
        }

        [$colour, $quality] = $parts['ink'][$print['ink']] ?? [null, null];
        $paper = $parts['paper'][$print['ink']][$print['paper']] ?? null;

        if (! $colour || ! $paper) {
            return ['id' => null, 'problem' => 'This ink and paper have no Lulu code here. Choose white paper, or black-and-white ink.'];
        }

        $finish = $parts['finish'][$print['finish']] ?? $parts['finish']['matte'];

        return ['id' => implode('.', [$trimCode, $colour, $quality, $parts['binding'], $paper, $finish]), 'problem' => null];
    }

    /** What the code says, in words, for the screen. */
    public static function describe(string $id): string
    {
        $bits = explode('.', $id);

        if (count($bits) !== 6) {
            return $id;
        }

        [$trim, $ink, $quality, $binding, $paper, $finish] = $bits;
        $inches = fn (string $s) => rtrim(rtrim(number_format(((int) $s) / 100, 2), '0'), '.');
        [$w, $h] = explode('X', $trim);

        return sprintf('%s × %s in, %s, %s quality, %s, %s paper, %s cover',
            $inches($w), $inches($h),
            $ink === 'BW' ? 'black and white' : 'full colour',
            $quality === 'PRE' ? 'premium' : 'standard',
            $binding === 'PB' ? 'paperback (perfect bound)' : $binding,
            match ($paper) {
                '060UC444' => '60# cream', '080CW444' => '80# coated white', default => '60# white'
            },
            str_starts_with($finish, 'G') ? 'gloss' : 'matte');
    }
}
