<?php

use App\Models\Book;
use App\Models\ResearchSource;
use App\Models\User;
use App\Research\Doi\CrossrefDoiLookup;
use App\Research\Doi\DoiLookup;
use App\Research\Import\BibTex;
use App\Research\Import\BibTexExport;
use App\Research\Import\Duplicates;
use App\Research\Import\Entry;
use App\Research\Import\Latex;
use App\Research\Import\Ris;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDoiLookup;

/**
 * Importing a research library (R3): BibTeX, RIS, a DOI, and export back
 * to BibTeX. Everything imported is somebody else's data and arrives
 * unverified.
 */
function researchFixture(string $name): string
{
    return file_get_contents(base_path('tests/Fixtures/research/'.$name));
}

function researchUpload(string $name, ?string $as = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($as ?? $name, researchFixture($name));
}

beforeEach(function () {
    // No test here may reach the network.
    Http::preventStrayRequests();
});

// --- Parsers ---------------------------------------------------------------

it('reads a real-world BibTeX file: accents, braces, macros, several authors', function () {
    $entries = BibTex::parse(researchFixture('library.bib'));

    expect($entries)->toHaveCount(5);

    [$article, $who, $proceedings] = $entries;

    expect($article['kind'])->toBe('journal_article')
        ->and($article['title'])->toBe('Lavi nan mòn yo : une étude des jardins créoles')
        ->and($article['authors'])->toBe('Préval, René; François, Marie-Thérèse; et al.')
        ->and($article['container'])->toBe('Journal of Haitian Studies')
        ->and($article['published'])->toBe('2019')
        ->and($article['volume'])->toBe('25')
        ->and($article['issue'])->toBe('2')
        ->and($article['pages'])->toBe('112–140')
        ->and($article['doi'])->toBe('10.1353/jhs.2019.0012');

    // A braced corporate author is never inverted into "Organization, W. H.".
    expect($who['kind'])->toBe('book')
        ->and($who['authors'])->toBe('World Health Organization')
        ->and($who['title'])->toBe('Guidelines on Physical Activity and Sedentary Behaviour')
        ->and($who['publisher'])->toBe('WHO Press')
        ->and($who['isbn'])->toBe('978-92-4-001512-8')
        ->and($who['url'])->toBe('https://www.who.int/publications/i/item/9789240015128');

    expect($proceedings['authors'])->toBe('Déjean, Jean-Baptiste; Lee, K.; González, María')
        ->and($proceedings['title'])->toBe('Kreyòl Ayisyen & les corpus : premiers résultats')
        ->and($proceedings['container'])->toBe('Actes de la conférence TALN')
        ->and($proceedings['notes'])->toBe('Lu le 3 mai ; voir p. 4.')
        // A URL's own characters are not LaTeX.
        ->and($proceedings['url'])->toBe('https://aclanthology.org/2020.jeptalnrecital-taln.1/');
});

it('decodes the LaTeX accents French and Kreyòl names use', function (string $in, string $out) {
    expect(Latex::decode($in))->toBe($out);
})->with([
    ['Fran{\c c}ois', 'François'],
    ['Fran\c{c}ois', 'François'],
    ["{\\'E}cole", 'École'],
    ['\`a la m\^eme f\^ete', 'à la même fête'],
    ['Na\"{\i}ve', 'Naïve'],
    ['K\`ap pale Krey\`ol', 'Kàp pale Kreyòl'],
    ['Stra{\ss}e \o{} \ae', 'Straße ø æ'],
    ['\v{S}koda --- 50\%', 'Škoda — 50%'],
    ['{Already UTF-8: Kreyòl é}', 'Already UTF-8: Kreyòl é'],
]);

it('splits authors on "and" only outside braces', function () {
    expect(BibTex::people('{Barnes and Noble} and Smith, J.'))->toBe('Barnes and Noble; Smith, J.');
});

it('reads a RIS file: repeated authors, page ranges, wrapped lines, DOI URLs', function () {
    $entries = Ris::parse(researchFixture('library.ris'));

    expect($entries)->toHaveCount(3);

    [$article, $book, $site] = $entries;

    expect($article['kind'])->toBe('journal_article')
        ->and($article['authors'])->toBe('Préval, René; Lee, K.')
        ->and($article['published'])->toBe('2019')
        ->and($article['pages'])->toBe('112–140')
        // An article's SN is an ISSN, not an ISBN.
        ->and($article['isbn'])->toBeNull()
        ->and(Entry::doi($article['doi']))->toBe('10.1353/JHS.2019.0012');

    expect($book['kind'])->toBe('book')
        ->and($book['isbn'])->toBe('978-0-8070-4311-2')
        ->and($book['notes'])->toBe('A long note that wraps onto a second line.');

    expect($site['kind'])->toBe('website')
        ->and($site['title'])->toBe("Ministère de l'Éducation nationale — Programmes")
        ->and($site['authors'])->toBeNull();
});

it('never lets markup or a script URL through', function () {
    $evil = collect(BibTex::parse(researchFixture('library.bib')))->last();
    $clean = Entry::clean($evil);

    expect($clean['title'])->toBe('alert(1)Ordinary title bold')
        ->and($clean['url'])->toBeNull()
        ->and($clean['kind'])->toBe('website');

    // Encoded markup is decoded and then stripped, not left to be decoded later.
    expect(Entry::clean(['title' => '&lt;img src=x onerror=alert(1)&gt;Safe'])['title'])->toBe('Safe')
        // No title, no entry.
        ->and(Entry::clean(['title' => '  ', 'authors' => 'X']))->toBeNull()
        ->and(Entry::clean(['title' => 'x', 'url' => 'data:text/html,hi'])['url'])->toBeNull();
});

it('cuts over-long fields to what the columns hold', function () {
    $clean = Entry::clean([
        'title' => str_repeat('Long title ', 60),
        'authors' => implode('; ', array_map(fn ($i) => "Author{$i}, A.", range(1, 60))),
        'volume' => str_repeat('9', 100),
    ]);

    expect(mb_strlen($clean['title']))->toBe(255)
        ->and(mb_strlen($clean['authors']))->toBeLessThanOrEqual(255)
        ->and($clean['authors'])->toEndWith('; et al.')
        ->and(mb_strlen($clean['volume']))->toBe(40);
});

it('treats the same DOI, address or title as one work', function () {
    $seen = new Duplicates([
        ['title' => 'Silencing the Past', 'doi' => '10.1000/ABC', 'url' => null, 'label' => 'A'],
        ['title' => 'Other', 'doi' => null, 'url' => 'https://www.example.org/page/', 'label' => 'B'],
        ['title' => 'Introduction', 'doi' => '10.1000/one', 'url' => null, 'label' => 'C'],
    ]);

    expect($seen->of(['title' => 'x', 'doi' => 'https://doi.org/10.1000/abc']))->toBe('A')
        ->and($seen->of(['title' => 'y', 'url' => 'http://example.org/page']))->toBe('B')
        ->and($seen->of(['title' => 'SILENCING — the  past!']))->toBe('A')
        ->and($seen->of(['title' => 'Introduction', 'doi' => '10.1000/two']))->toBeNull()
        ->and($seen->of(['title' => 'Something new']))->toBeNull();
});

// --- HTTP ------------------------------------------------------------------

it('previews a file without saving, flagging what the library already has', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    ResearchSource::factory()->for($book)->create(['title' => 'Something else', 'doi' => '10.1353/JHS.2019.0012']);

    $response = $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import/preview", ['file' => researchUpload('library.bib')], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('format', 'bibtex')
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('truncated', false);

    $entries = $response->json('entries');
    expect($entries)->toHaveCount(4)
        ->and($entries[0]['duplicate_of'])->toBe('Something else')
        ->and($entries[1]['duplicate_of'])->toBeNull()
        ->and($book->sources()->count())->toBe(1);
});

it('imports the ticked rows as unverified, skipping duplicates', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import", [
            'file' => researchUpload('library.ris'),
            'only' => [0, 1],
            'collection' => 'Thèse — chapitre 2',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('imported', 2)
        ->assertJsonPath('duplicates', 0);

    $sources = $book->sources()->orderBy('id')->get();
    expect($sources)->toHaveCount(2)
        ->and($sources->pluck('verification')->unique()->all())->toBe(['unverified'])
        ->and($sources->pluck('origin')->unique()->all())->toBe(['ris'])
        ->and($sources->pluck('collection')->unique()->all())->toBe(['Thèse — chapitre 2'])
        ->and($sources[0]->added_by)->toBe($user->id)
        ->and($sources[0]->doi)->toBe('10.1353/JHS.2019.0012');

    // The same file again: nothing new.
    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import", ['file' => researchUpload('library.ris')], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('imported', 1)
        ->assertJsonPath('duplicates', 2);

    // And the BibTeX version of the same article is recognised by its DOI.
    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import", ['file' => researchUpload('library.bib'), 'only' => [0]], ['Accept' => 'application/json'])
        ->assertJsonPath('imported', 0)
        ->assertJsonPath('duplicates', 1);
});

it('never imports a file as verified, whatever the file says', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $bib = "@article{x, title = {Claims to be checked}, verification = {verified}, x-editons-verification = {verified}}";

    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import", ['file' => UploadedFile::fake()->createWithContent('x.bib', $bib)], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($book->sources()->sole()->verification)->toBe('unverified');
});

it('refuses another author\'s book, binaries, oversize and empty files', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $theirs = Book::factory()->create();

    $this->actingAs($user)
        ->post("/books/{$theirs->id}/sources/import", ['file' => researchUpload('library.bib')], ['Accept' => 'application/json'])
        ->assertForbidden();

    $this->actingAs($user)
        ->post("/books/{$theirs->id}/sources/import/preview", ['file' => researchUpload('library.bib')], ['Accept' => 'application/json'])
        ->assertForbidden();

    expect($theirs->sources()->count())->toBe(0);

    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import/preview", ['file' => UploadedFile::fake()->createWithContent('a.bib', "%PDF\0\0binary")], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import/preview", ['file' => UploadedFile::fake()->create('big.bib', 2048)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import/preview", ['file' => UploadedFile::fake()->createWithContent('a.bib', 'just some words')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('reads a Windows-1252 RIS export', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $ris = mb_convert_encoding("TY  - BOOK\nTI  - Les Misérables\nAU  - Hugo, Victor\nER  - \n", 'Windows-1252', 'UTF-8');

    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import/preview", ['file' => UploadedFile::fake()->createWithContent('old.txt', $ris)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('format', 'ris')
        ->assertJsonPath('entries.0.title', 'Les Misérables');
});

it('looks a DOI up through the registry interface and saves nothing', function () {
    $fake = new FakeDoiLookup([
        '10.1000/kreyol' => [
            'kind' => 'journal_article',
            'title' => '<i>Kreyòl</i> and schooling in Haiti',
            'authors' => 'DeGraff, Michel',
            'published' => '2016',
            'container' => 'Language',
            'url' => 'https://doi.org/10.1000/kreyol',
        ],
        '10.1000/down' => false,
    ]);
    app()->instance(DoiLookup::class, $fake);

    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/sources/doi", ['doi' => 'https://doi.org/10.1000/KREYOL'])
        ->assertOk()
        ->assertJsonPath('entry.title', 'Kreyòl and schooling in Haiti')
        ->assertJsonPath('entry.doi', '10.1000/KREYOL')
        ->assertJsonPath('duplicate_of', null);

    expect($fake->asked)->toBe(['10.1000/KREYOL'])
        ->and($book->sources()->count())->toBe(0);

    $this->actingAs($user)->postJson("/books/{$book->id}/sources/doi", ['doi' => '10.1000/missing'])
        ->assertUnprocessable()->assertJsonValidationErrors('doi');
    $this->actingAs($user)->postJson("/books/{$book->id}/sources/doi", ['doi' => 'not a doi'])
        ->assertUnprocessable()->assertJsonValidationErrors('doi');
    $this->actingAs($user)->postJson("/books/{$book->id}/sources/doi", ['doi' => '10.1000/down'])
        ->assertStatus(503);

    // Saved from the lookup, it is still unverified, and says where it came from.
    $this->actingAs($user)
        ->postJson("/books/{$book->id}/sources", ['kind' => 'journal_article', 'title' => 'Kreyòl and schooling in Haiti', 'origin' => 'crossref'])
        ->assertCreated()
        ->assertJsonPath('sources.0.verification', 'unverified')
        ->assertJsonPath('sources.0.origin', 'crossref');
});

it('maps a Crossref work record', function () {
    $fields = CrossrefDoiLookup::map([
        'type' => 'journal-article',
        'title' => ['Silencing the Past'],
        'author' => [['given' => 'Michel-Rolph', 'family' => 'Trouillot'], ['name' => 'Beacon Collective']],
        'container-title' => ['Callaloo'],
        'issued' => ['date-parts' => [[1995, 3]]],
        'volume' => '18', 'issue' => '2', 'page' => '10-20',
        'DOI' => '10.2307/3299092', 'URL' => 'https://doi.org/10.2307/3299092',
    ]);

    expect($fields['authors'])->toBe('Trouillot, Michel-Rolph; Beacon Collective')
        ->and($fields['published'])->toBe('1995')
        ->and($fields['pages'])->toBe('10–20')
        ->and($fields['kind'])->toBe('journal_article');
});

it('exports the library as BibTeX that reads back the same', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['title' => 'Mon livre']);

    $this->actingAs($user)
        ->post("/books/{$book->id}/sources/import", ['file' => researchUpload('library.bib')], ['Accept' => 'application/json'])
        ->assertCreated();

    $response = $this->actingAs($user)->get("/books/{$book->id}/sources/export.bib")->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('mon-livre.bib');

    $back = collect(BibTex::parse($response->getContent()))->keyBy('title');
    $original = $book->sources()->get()->keyBy('title');

    expect($back->keys()->sort()->values()->all())->toBe($original->keys()->sort()->values()->all());

    foreach (['authors', 'container', 'pages', 'doi', 'url', 'published'] as $field) {
        foreach ($original as $title => $source) {
            expect($back[$title][$field] ?? null)->toBe($source->{$field}, "{$title}: {$field}");
        }
    }

    // Someone else cannot download it.
    $this->actingAs(User::factory()->create())->get("/books/{$book->id}/sources/export.bib")->assertForbidden();
});

it('escapes what means something to TeX', function () {
    $source = new ResearchSource(['title' => '50% of R&D_costs {sic}', 'authors' => 'World Health Organization; Smith, J.']);
    $source->kind = 'report';
    $source->verification = 'unverified';

    $bib = BibTexExport::render([$source]);

    expect($bib)->toContain('title = {50\% of R\&D\_costs sic}')
        ->and($bib)->toContain('author = {{World Health Organization} and Smith, J.}')
        ->and($bib)->toStartWith('@techreport{worldhealthorganizat,');
});
