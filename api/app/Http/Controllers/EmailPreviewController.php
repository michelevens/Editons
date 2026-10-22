<?php

namespace App\Http\Controllers;

use App\Mail\EmailPreferences;
use App\Mail\Support\EmailCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /dev/emails — every email, rendered with sample data, for review.
 *
 * Registered only when the app runs locally (routes/emails.php), and the
 * sample data is built in memory by EmailCatalog: nothing is read from or
 * written to the database, and nothing is sent.
 */
class EmailPreviewController extends Controller
{
    public function index(): Response
    {
        $emails = collect(EmailCatalog::all())->map(function (array $entry, string $slug) {
            $mail = ($entry['make'])(null);
            $letter = $mail->letter();

            return [
                'slug' => $slug,
                'group' => $entry['group'],
                'label' => $entry['label'],
                'trigger' => $entry['trigger'],
                'subject' => $letter->subject,
                'preheader' => $letter->preheader,
                'severity' => $letter->severity,
                'category' => EmailPreferences::CATEGORIES[$mail->category()]['label'],
                'mandatory' => EmailPreferences::isMandatory($mail->category()),
                'queued' => $mail instanceof ShouldQueue,
            ];
        })->groupBy('group');

        return response()->view('dev.emails', ['groups' => $emails]);
    }

    public function show(Request $request, string $name): Response
    {
        abort_unless(array_key_exists($name, EmailCatalog::all()), 404);

        $mail = EmailCatalog::make($name, $request->query('title'));

        if ($request->query('format') === 'text') {
            return response(
                view('emails.text.standard', $mail->viewData())->render(),
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return response($mail->render());
    }
}
