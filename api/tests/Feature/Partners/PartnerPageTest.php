<?php

use App\Commerce\Partners\Handles;
use App\Commerce\Partners\PartnerPages;
use App\Models\ContentReport;
use App\Models\PartnerHandle;
use App\Models\PartnerShelfItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The branded page: handle rules and redirects, what is escaped and what is
 * required, who can see it when, and the shelf only showing what may be sold.
 */

beforeEach(fn () => partnerProgram());

it('accepts only well-formed, unreserved, unused handles', function (string $handle, bool $ok) {
    partnerFor(attributes: ['handle' => 'taken-one']);

    expect(Handles::problem($handle) === null)->toBe($ok);
})->with([
    ['lekol-sen-jozef', true],
    ['abc', true],
    [str_repeat('a', 30), true],
    ['ab', false],
    [str_repeat('a', 31), false],
    ['Upper', false],
    ['-lead', false],
    ['trail-', false],
    ['dou--ble', false],
    ['spa ce', false],
    ['émile', false],
    ['admin', false],
    ['ecrivain', false],
    ['store', false],
    ['taken-one', false],
]);

it('lets a partner change their handle once every 30 days, keeping the old one as a 301 redirect', function () {
    $partner = partnerFor(attributes: ['handle' => 'first-name']);

    Handles::change($partner, 'second-name');
    expect($partner->fresh()->handle)->toBe('second-name')
        ->and(PartnerHandle::where('handle', 'first-name')->exists())->toBeTrue()
        // Nobody else can take the old one.
        ->and(Handles::problem('first-name'))->not->toBeNull();

    expect(fn () => Handles::change($partner->fresh(), 'third-name'))->toThrow(ValidationException::class);

    $this->get('/p/first-name')->assertRedirect('/p/second-name')->assertStatus(301);

    $this->travel(31)->days();
    Handles::change($partner->fresh(), 'third-name');
    $this->get('/p/first-name')->assertRedirect('/p/third-name');
});

it('renders what a partner typed as text, with the disclosure and proper meta tags', function () {
    $partner = partnerFor(attributes: [
        'display_name' => 'Madame <b>Joseph</b>',
        'tagline' => 'Books for <script>alert(1)</script> Philo',
        'bio' => "Line one\nLine two <img src=x onerror=alert(1)>",
    ]);

    $response = $this->get("/p/{$partner->handle}")->assertOk();
    $html = $response->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toMatch('/<img src=x/')
        ->and($html)->toContain('<meta property="og:title" content="Madame &lt;b&gt;Joseph&lt;/b&gt; · Écrivain Partner">')
        ->and($html)->not->toContain('name="robots"');

    $response->assertInertia(fn (Assert $page) => $page->component('Partners/Page')
        ->where('live', true)
        ->where('page.disclosure', 'Madame <b>Joseph</b> earns a commission on purchases made through this page.')
        ->where('page.bio', "Line one\nLine two <img src=x onerror=alert(1)>"));
});

it('keeps what the partner typed as text, with its line breaks, when they save their page', function () {
    $partner = partnerFor();

    $this->actingAs($partner->user)->put(route('partners.page.update'), [
        'handle' => $partner->handle,
        'display_name' => 'Bibliothèque Lakay',
        'tagline' => 'Livres <em>pour</em> tous',
        'bio' => "Premier\r\nDeuxième <script>x</script>",
        'accent' => '#FFDD00',
        'page_locale' => 'fr',
        'socials' => [['kind' => 'whatsapp', 'value' => '+509 3456-7890'], ['kind' => 'website', 'value' => 'https://lakay.example']],
        'shelf' => [],
    ])->assertSessionHasNoErrors();

    $partner->refresh();
    expect($partner->tagline)->toBe('Livres <em>pour</em> tous')
        ->and($partner->bio)->toBe("Premier\nDeuxième <script>x</script>")
        ->and($partner->accent)->toBe('#ffdd00')
        ->and($partner->socials)->toBe([['kind' => 'whatsapp', 'url' => 'https://wa.me/50934567890'], ['kind' => 'website', 'url' => 'https://lakay.example']])
        ->and(PartnerPages::disclosure($partner))->toBe('Bibliothèque Lakay touche une commission sur les achats effectués depuis cette page.');
});

it('refuses links that are not https', function (string $link) {
    $partner = partnerFor();

    $this->actingAs($partner->user)->put(route('partners.page.update'), [
        'handle' => $partner->handle, 'display_name' => 'X Partner', 'page_locale' => 'en',
        'socials' => [['kind' => 'other', 'value' => $link]], 'shelf' => [],
    ])->assertSessionHasErrors('socials.0.value');
})->with(['javascript:alert(1)', 'http://plain.example', 'data:text/html,hi', 'ftp://x.example']);

it('picks a readable text colour for any accent', function () {
    foreach (['#ffdd00', '#ffffff', '#2166f5', '#000000', '#7c3aed', '#10b981'] as $accent) {
        $ink = PartnerPages::readableInk($accent);

        expect(PartnerPages::contrast($accent, $ink))->toBeGreaterThanOrEqual(4.5);
    }

    expect(PartnerPages::readableInk('#ffdd00'))->toBe('#111827')
        ->and(PartnerPages::readableInk('#1e3a8a'))->toBe('#ffffff');
});

it('shows a page before approval only to its partner, marked noindex', function () {
    $partner = partnerFor(attributes: ['status' => 'applied', 'approved_at' => null]);

    $this->get("/p/{$partner->handle}")
        ->assertNotFound()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn (Assert $page) => $page->where('page', null));

    $own = $this->actingAs($partner->user)->get("/p/{$partner->handle}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($own->getContent())->toContain('<meta name="robots" content="noindex, nofollow">');
    $own->assertInertia(fn (Assert $page) => $page->where('preview', true)->where('live', false));
});

it('takes a suspended partner’s page offline with a neutral page', function () {
    $partner = partnerFor(attributes: ['status' => 'suspended', 'tagline' => 'Secret tagline']);

    $response = $this->get("/p/{$partner->handle}")->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->getContent())->not->toContain('Secret tagline')
        ->and($response->getContent())->not->toContain($partner->display_name);
});

it('shows only books the partner may still sell, in their order, with their notes', function () {
    $partner = partnerFor();
    $first = partnerProduct();
    $second = partnerProduct();
    $closed = partnerProduct();

    $this->actingAs($partner->user)->put(route('partners.page.update'), [
        'handle' => $partner->handle, 'display_name' => $partner->display_name, 'page_locale' => 'en',
        'shelf' => [
            ['product' => $second->slug, 'note' => 'My students <3 it'],
            ['product' => $first->slug],
            ['product' => $closed->slug],
        ],
    ])->assertSessionHasNoErrors();

    // The seller closes one to partners afterwards.
    $closed->forceFill(['partner_enabled' => false])->save();
    auth()->logout();

    $this->get("/p/{$partner->handle}")->assertInertia(fn (Assert $page) => $page
        ->has('page.books', 2)
        ->where('page.books.0.slug', $second->slug)
        ->where('page.books.0.note', 'My students <3 it')
        ->where('page.books.0.buy_url', route('partners.page.book', [$partner->handle, $second->slug]))
        ->where('page.books.1.slug', $first->slug));
});

it('refuses a shelf with books that cannot be sold through partners', function () {
    $partner = partnerFor();
    $closed = partnerProduct(rate: null);

    $this->actingAs($partner->user)->put(route('partners.page.update'), [
        'handle' => $partner->handle, 'display_name' => 'Name', 'page_locale' => 'en',
        'shelf' => [['product' => $closed->slug]],
    ])->assertSessionHasErrors('shelf');

    expect(PartnerShelfItem::count())->toBe(0);
});

it('serves the page and a book as QR codes, SVG and PNG', function () {
    $partner = partnerFor();
    $product = partnerProduct();

    $svg = $this->get("/p/{$partner->handle}/qr.svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($svg->getContent())->toStartWith('<?xml');

    $png = $this->get("/p/{$partner->handle}/qr.png?book={$product->slug}")->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(substr($png->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($png->headers->get('Content-Disposition'))->toContain($product->slug);
});

it('re-encodes an uploaded logo as a square PNG, and refuses what is not an image', function () {
    Storage::fake('files');
    $partner = partnerFor();

    $this->actingAs($partner->user)->post(route('partners.logo'), ['logo' => UploadedFile::fake()->image('me.jpg', 900, 600)])->assertSessionHasNoErrors();

    $path = $partner->fresh()->logo_path;
    $size = getimagesizefromstring(Storage::disk('files')->get($path));
    expect($size[0])->toBe(512)->and($size[1])->toBe(512)->and($size['mime'])->toBe('image/png');

    $this->actingAs($partner->user)->post(route('partners.logo'), ['logo' => UploadedFile::fake()->create('evil.svg', 4, 'image/svg+xml')])->assertSessionHasErrors('logo');
});

it('sends a report on a partner page to the moderation queue', function () {
    $partner = partnerFor();

    $this->post("/p/{$partner->handle}/report", ['category' => 'misleading', 'details' => 'Claims to be the author'])->assertSessionHas('platform_notice');

    $report = ContentReport::sole();
    expect($report->partner_id)->toBe($partner->id)->and($report->product_id)->toBeNull();

    $this->actingAs(platformAdmin('support'))->get(route('admin.moderation.index'))
        ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.partner.id', $partner->id));
});
