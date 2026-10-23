<?php

use App\Mail\Publishing\ExportReadyMail;
use App\Models\BookExport;
use App\Models\User;
use App\Publishing\CoverRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * The book card in an email shows the book's real cover.
 *
 * It used to be a coloured block with the title set in it, because the
 * rendered cover is behind sign-in and a linked picture is a broken box in
 * most inboxes. The thumbnail now travels inside the message (cid:), so the
 * author sees their own cover — the one they uploaded — in the export email.
 */
function coverEmail(): array
{
    Storage::fake('files');
    config(['mail.default' => 'array']);

    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Réussir le Bac', 'author_name' => null]);
    $export = BookExport::forceCreate([
        'book_id' => $book->id,
        'format' => 'pdf',
        'status' => 'ready',
        'path' => "exports/book-{$book->id}/reussir.pdf",
    ]);

    return [$user, $book, $export];
}

function sentEmail(): Symfony\Component\Mime\Email
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

it('embeds the book cover in the export email', function () {
    [$user, $book, $export] = coverEmail();

    Mail::mailer('array')->to($user)->sendNow(new ExportReadyMail($export, $user));

    $email = sentEmail();
    $inline = collect($email->getAttachments())->filter(fn ($part) => $part->getDisposition() === 'inline');

    expect($inline)->toHaveCount(1)
        ->and($inline->first()->getMediaType().'/'.$inline->first()->getMediaSubtype())->toBe('image/jpeg')
        ->and($email->getHtmlBody())->toContain('src="cid:')
        ->and($email->getHtmlBody())->toContain('Cover of Réussir le Bac')
        // The thumbnail it embedded is the book's own, from the cover renderer.
        ->and(Storage::disk('files')->exists(app(CoverRenderer::class)->ensureThumb($book->fresh())))->toBeTrue();
});

it('falls back to the typographic cover when the cover cannot be drawn', function () {
    [$user, $book, $export] = coverEmail();

    $this->mock(CoverRenderer::class)->shouldReceive('ensureThumb')->andThrow(new RuntimeException('GD is missing.'));

    Mail::mailer('array')->to($user)->sendNow(new ExportReadyMail($export, $user));

    $email = sentEmail();

    expect(collect($email->getAttachments())->filter(fn ($part) => $part->getDisposition() === 'inline'))->toBeEmpty()
        ->and($email->getHtmlBody())->not->toContain('cid:')
        ->and($email->getHtmlBody())->toContain('Réussir le Bac');
});
