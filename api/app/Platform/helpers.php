<?php

use App\Models\User;
use App\Platform\Features;

if (! function_exists('feature')) {
    /**
     * Is a module on for this person? feature('storefront', $user).
     *
     * Without a user, the signed-in one. See App\Platform\Features for the
     * order things are decided in.
     */
    function feature(string $key, ?User $user = null): bool
    {
        return Features::enabled($key, $user ?? auth()->user());
    }
}
