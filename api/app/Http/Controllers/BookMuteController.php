<?php

namespace App\Http\Controllers;

use App\Mail\NotificationSettings;
use App\Models\Book;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mute or unmute one book's notifications, from its card on the shelf or
 * from the profile's notification settings (App\Mail\NotificationSettings).
 *
 * A mute is the person's own setting, not the book's: a collaborator can
 * mute a book they were invited to, and the owner never knows. Anybody who
 * can open the book may mute it for themselves.
 */
class BookMuteController extends Controller
{
    public function update(Request $request, Book $book): RedirectResponse
    {
        $this->authorize('view', $book);

        $data = $request->validate(['muted' => ['required', 'boolean']]);

        NotificationSettings::mute($request->user(), $book, $data['muted']);

        return back()->with('status', $data['muted'] ? 'book-muted' : 'book-unmuted');
    }
}
