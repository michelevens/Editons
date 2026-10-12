<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 13 ships a bare base controller — AuthorizesRequests is no longer
 * included by default, so $this->authorize() is undefined until the trait is
 * added here.
 *
 * Worth knowing how that failed: the authorisation calls were in place and
 * threw "Call to undefined method", which surfaces as a 500 rather than a
 * 403. A missing permission check reading as a server bug is exactly the kind
 * of thing that gets "fixed" by deleting the call. The ownership tests caught
 * it because they assert 403 specifically.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
