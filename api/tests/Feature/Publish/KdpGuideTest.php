<?php

use App\Models\Book;
use App\Platform\House\HouseAccounts;
use App\Publishing\Publish\GooglePlayBulk;
use App\Publishing\Publish\KdpGuide;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
 * The Amazon KDP guided page and the Google Play bulk upload: every value
 * the store's form asks for, within the store's own limits, and never an
 * author name a book does not carry.
 */

beforeEach(function () {
    Storage::fake('files');
    Mail::fake();
    $this->withoutVite();
});

function kdpField(array $guide, string $key): ?array
{
    return collect($guide['sections'])->flatMap(fn ($s) => $s['fields'])->firstWhere('key', $key);
}

it('lays out KDP\'s three pages for the ebook and the paperback, in order', function () {
    $book = pfChoose(pfBook(), 'kdp_ebook', 'kdp_paperback');
    $guide = app(KdpGuide::class);

    $ebook = $guide->for($book, 'ebook');
    $paperback = $guide->for($book, 'paperback');

    expect(array_column($ebook['sections'], 'key'))->toBe(['details', 'content', 'pricing'])
        ->and(kdpField($ebook, 'language')['value'])->toBe('English')
        ->and(kdpField($ebook, 'title')['value'])->toBe('Lakou')
        ->and(kdpField($ebook, 'subtitle')['value'])->toBe('Stories from the yard')
        ->and(kdpField($ebook, 'author')['value'])->toBe('A Writer')
        ->and(kdpField($ebook, 'publishing_rights')['status'])->toBe('ok')
        ->and(kdpField($ebook, 'ai')['value'])->toBe(['Text: No', 'Images: No', 'Translations: No'])
        ->and(kdpField($ebook, 'price')['value'])->toBe('4.99')
        ->and(kdpField($ebook, 'royalty_plan')['value'])->toBe('70%')
        ->and(kdpField($ebook, 'file_epub')['status'])->toBe('fail')
        ->and(kdpField($paperback, 'ink')['value'])->toBe('Black & white interior with white paper')
        ->and(kdpField($paperback, 'trim')['value'])->toBe('6 x 9 in')
        ->and(kdpField($paperback, 'bleed')['value'])->toBe('No Bleed')
        ->and(kdpField($paperback, 'finish')['value'])->toBe('Matte')
        ->and(kdpField($paperback, 'isbn')['value'])->toBe('Get a free KDP ISBN')
        ->and(kdpField($paperback, 'price')['value'])->toBe('14.99')
        ->and(kdpField($paperback, 'price')['royalty'])->toBeFloat();

    // With fresh files, each one is a download.
    pfFile($book, 'epub');
    expect(kdpField($guide->for($book->fresh(), 'ebook'), 'file_epub'))
        ->status->toBe('ok')
        ->download->toContain("/books/{$book->id}/exports/");
});

it('holds the description to 4000 characters with its HTML, escaped, in paragraphs', function () {
    expect(KdpGuide::descriptionHtml("First <b>bold</b> & more.\nSame paragraph.\n\nSecond."))
        ->toBe('<p>First bold &amp; more.<br>Same paragraph.</p><p>Second.</p>');

    $book = pfChoose(pfBook(), 'kdp_ebook');
    $book->update(['meta' => array_merge($book->meta, ['description' => str_repeat('x', 3993)])]);
    expect(kdpField(app(KdpGuide::class)->for($book->fresh(), 'ebook'), 'description')['status'])->toBe('ok'); // 3993 + <p></p> = 4000

    $book->update(['meta' => array_merge($book->meta, ['description' => str_repeat('x', 3994)])]);
    $field = kdpField(app(KdpGuide::class)->for($book->fresh(), 'ebook'), 'description');
    expect($field['status'])->toBe('fail')->and($field['note'])->toContain('4001 characters');
});

it('fails a title and subtitle of 200 characters or more together', function () {
    $book = pfBook(attributes: ['title' => str_repeat('T', 100), 'subtitle' => str_repeat('S', 99)]);
    expect(kdpField(app(KdpGuide::class)->for($book, 'ebook'), 'title')['status'])->toBe('ok');

    $book->update(['subtitle' => str_repeat('S', 100)]);
    expect(kdpField(app(KdpGuide::class)->for($book->fresh(), 'ebook'), 'title'))
        ->status->toBe('fail')
        ->note->toContain('fewer than 200');
});

it('suggests seven keywords of at most 50 characters, and saves the author\'s edits within those limits', function () {
    $book = pfChoose(pfBook(attributes: ['series_name' => 'Yard Tales', 'meta' => [
        'description' => str_repeat('A clear and useful description of the book. ', 5),
        'category' => 'Fiction / Literary / Caribbean',
        'keywords' => 'yard stories, haiti, family saga, '.str_repeat('very long keyword ', 5).', island life, port-au-prince, kreyol, extra one',
    ]]), 'kdp_ebook');

    $suggested = KdpGuide::suggestKeywords($book);
    expect($suggested)->toHaveCount(7)
        ->and(collect($suggested)->every(fn ($k) => mb_strlen($k) <= 50))->toBeTrue()
        ->and($suggested[0])->toBe('yard stories');

    $this->actingAs($book->user);
    $this->patchJson("/books/{$book->id}/publish-flow/kdp", ['keywords' => array_fill(0, 8, 'too many')])->assertStatus(422);
    $this->patchJson("/books/{$book->id}/publish-flow/kdp", ['keywords' => [str_repeat('k', 51)]])->assertStatus(422);
    $this->patchJson("/books/{$book->id}/publish-flow/kdp", ['categories' => ['a', 'b', 'c', 'd']])->assertStatus(422);

    $this->patchJson("/books/{$book->id}/publish-flow/kdp", [
        'keywords' => ['  haitian   fiction ', '', 'lakou life'],
        'categories' => ['Fiction > Literary'],
        'explicit' => false,
    ])->assertOk()
        ->assertJsonPath('kdp.ebook.sections.0.fields', fn ($fields) => collect($fields)->firstWhere('key', 'keywords')['value'] === ['haitian fiction', 'lakou life']
            && collect($fields)->firstWhere('key', 'audience')['status'] === 'ok');

    // The edit does not make the files stale.
    expect($book->fresh()->updated_at->equalTo($book->updated_at))->toBeTrue();
});

it('gives an anonymous house book only its imprint\'s name, never a person\'s, at KDP or Google', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
    $book = pfChoose(pfBook($house, ['author_name' => null, 'series_name' => 'Écrivain Prépa', 'series_number' => 3, 'language' => 'French']), 'kdp_ebook', 'google_play');
    pfIsbn($book, '9780306406157');

    $author = kdpField(app(KdpGuide::class)->for($book->fresh(), 'ebook'), 'author');
    // KDP requires a primary author: the imprint fills it (owner decision).
    expect($author['value'])->toBe('Écrivain Prépa')
        ->and($author['status'])->toBe('ok')
        ->and($author['note'])->toContain('without a personal author name');

    $row = app(GooglePlayBulk::class)->row($book->fresh(), '9780306406157');
    // Every value the KDP page gives and every cell of Google's row.
    $values = collect(app(KdpGuide::class)->for($book->fresh(), 'ebook')['sections'])->flatMap(fn ($s) => $s['fields'])->pluck('value')->flatten()->filter();
    $all = $values->implode(' ').' '.implode(' ', $row);

    expect($row['Contributor [Role], Semicolon-Separated'])->toBe('')
        ->and($row['Publisher Name'])->toBe('Écrivain Prépa')
        ->and($row['Series Name'])->toBe('Écrivain Prépa')
        ->and($row['Volume in Series'])->toBe('3')
        ->and($row['Language'])->toBe('fre')
        ->and($all)->not->toContain($house->email)
        ->and($all)->not->toContain('Anonymous');

    // An author's book with no name is a problem to fix, not an anonymous one.
    $plain = pfBook(attributes: ['author_name' => null]);
    expect(kdpField(app(KdpGuide::class)->for($plain, 'ebook'), 'author')['status'])->toBe('fail');
});

it('builds Google\'s book list: tab-separated UTF-16, the help page\'s columns, files named by ISBN', function () {
    $a = pfChoose(pfBook(attributes: ['title' => 'With ISBN']), 'google_play');
    pfIsbn($a, '9780306406157');
    pfFile($a, 'epub');
    $b = pfChoose(pfBook($a->user, ['title' => 'No ISBN']), 'google_play');
    pfFile($b, 'epub');

    $bulk = app(GooglePlayBulk::class);
    $plan = $bulk->plan(collect([$a->fresh(), $b->fresh()]));

    expect($plan['rows'])->toHaveCount(1)
        ->and($plan['skipped'][0]['title'])->toBe('No ISBN')
        ->and($plan['skipped'][0]['reason'])->toContain('identifier');

    $tsv = $bulk->spreadsheet($plan['rows']);
    expect(substr($tsv, 0, 2))->toBe("\xFF\xFE");

    $text = mb_convert_encoding(substr($tsv, 2), 'UTF-8', 'UTF-16LE');
    [$header, $row] = explode("\r\n", $text);
    $cells = array_combine(explode("\t", $header), explode("\t", $row));

    expect(explode("\t", $header))->toBe(config('publishing.publish.google_play.columns'))
        ->and($cells['Identifier'])->toBe('9780306406157')
        ->and($cells['Title'])->toBe('With ISBN')
        ->and($cells['Book Format'])->toBe('Digital')
        ->and($cells['Enable for Sale?'])->toBe('Yes')
        ->and($cells['Contributor [Role], Semicolon-Separated'])->toBe('A Writer [Author]')
        ->and($cells['Language'])->toBe('eng')
        ->and($cells['Description'])->toStartWith('<p>A clear');

    $response = $this->actingAs($a->user)->get("/publish/google-play?books[]={$a->id}&books[]={$b->id}")->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();

    expect($names)->toBe(['9780306406157.epub', '9780306406157_frontcover.jpg', 'README.txt', 'google-play-book-list.tsv'])
        ->and($zip->getFromName('README.txt'))->toContain('No ISBN: No ebook ISBN');
});
