<?php

namespace App\Platform;

/**
 * Addresses as the console shows people who are not the account in front of
 * it: enough to tell two apart ("j•••@gmail.com"), not enough to write to.
 * A buyer of a book is the author's customer, not ours to read out.
 */
final class Mask
{
    public static function email(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email === null ? null : '•••';
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
