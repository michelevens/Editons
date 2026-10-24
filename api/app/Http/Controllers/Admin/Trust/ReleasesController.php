<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Platform\Trust\ReleaseInfo;
use Inertia\Inertia;
use Inertia\Response;

/** What is deployed. Read-only: nothing on this page changes anything. */
class ReleasesController extends Controller
{
    public function index(ReleaseInfo $release): Response
    {
        return Inertia::render('Admin/Trust/Releases', $release->describe());
    }
}
