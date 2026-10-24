<?php

namespace App\Publishing;

use App\Models\Book;

/**
 * The legal-deposit reminder for the book's country of publication.
 *
 * Most countries require a publisher to deposit copies of a printed book
 * with the national library. The rule depends on the country in the
 * publishing details; where config/publishing.php has it from an official
 * source, the reminder says how many copies and where, with the source.
 * Otherwise it is a general reminder, never a guessed number.
 */
class LegalDeposit
{
    /** @return array{country: ?string, message: string, source: ?string} */
    public static function for(Book $book): array
    {
        $country = $book->publication()['country'];
        $rule = config("publishing.legal_deposit.{$country}");

        if (! $rule) {
            return [
                'country' => $country,
                'message' => 'Many countries require publishers to deposit copies of a printed book with the national library. Check the rule where you publish.',
                'source' => null,
            ];
        }

        return [
            'country' => $country,
            'message' => sprintf(
                'Legal deposit: %d %s of the printed book to %s, under %s%s.',
                $rule['copies'],
                $rule['copies'] === 1 ? 'copy' : 'copies',
                $rule['to'],
                $rule['basis'],
                $rule['when'] ? ' ('.$rule['when'].')' : '',
            ),
            'source' => $rule['source'],
        ];
    }
}
