<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\ContentReport;
use App\Models\Product;
use App\Models\User;
use App\Platform\Trust\Moderation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every book on the platform, and every storefront listing — metadata only.
 *
 * The query names its columns: no manuscript text, description, notes or
 * cover data reaches this page, and a test holds it to that. Reading a book
 * is what "View as" is for.
 */
class CatalogController extends Controller
{
    /** The only book columns this page reads. */
    private const COLUMNS = ['id', 'user_id', 'title', 'book_type', 'language', 'word_count', 'status', 'created_at', 'updated_at', 'deleted_at'];

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $type = (string) $request->query('type', '');
        $language = (string) $request->query('language', '');
        $filter = (string) $request->query('filter', '');

        $books = Book::withTrashed()
            ->select(self::COLUMNS)
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(function ($w) use ($q, $like) {
                    if (ctype_digit($q)) {
                        $w->orWhere('id', (int) $q);
                    }

                    $w->orWhere('title', 'ilike', $like)
                        ->orWhereIn('user_id', User::where('email', 'ilike', $like)->orWhere('name', 'ilike', $like)->select('id'));
                });
            })
            ->when(in_array($status, Book::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($status === 'deleted', fn ($query) => $query->onlyTrashed())
            ->when($status === '', fn ($query) => $query->whereNull('deleted_at'))
            ->when($type !== '', fn ($query) => $query->where('book_type', $type))
            ->when($language !== '', fn ($query) => $query->where('language', $language))
            ->when($filter === 'on_sale', fn ($query) => $query->whereHas('product', fn ($p) => $p->published()))
            ->when($filter === 'taken_down', fn ($query) => $query->whereHas('product', fn ($p) => $p->whereNotNull('taken_down_at')))
            ->when($filter === 'reported', fn ($query) => $query->whereHas('product', fn ($p) => $p->whereIn('id', ContentReport::where('status', 'open')->whereNotNull('product_id')->select('product_id'))))
            ->withCount('exports')
            ->with(['user:id,name,email', 'product:id,book_id,slug,published_at,taken_down_at'])
            ->orderByDesc('updated_at')
            ->paginate(30)
            ->withQueryString();

        $reported = ContentReport::where('status', 'open')
            ->whereIn('product_id', $books->getCollection()->pluck('product.id')->filter())
            ->select('product_id', DB::raw('count(*) as n'))
            ->groupBy('product_id')
            ->pluck('n', 'product_id');

        return Inertia::render('Admin/Trust/Catalog', [
            'tab' => 'books',
            'filters' => compact('q', 'status', 'type', 'language', 'filter'),
            'options' => [
                'types' => Book::withTrashed()->whereNotNull('book_type')->distinct()->orderBy('book_type')->pluck('book_type'),
                'languages' => Book::withTrashed()->whereNotNull('language')->distinct()->orderBy('language')->pluck('language'),
            ],
            'books' => $books->through(fn (Book $book) => [
                'id' => $book->id,
                'title' => $book->title,
                'account' => $book->user ? ['id' => $book->user->id, 'name' => $book->user->name, 'email' => $book->user->email] : null,
                'type' => $book->book_type,
                'language' => $book->language,
                'words' => (int) $book->word_count,
                'status' => $book->trashed() ? 'deleted' : $book->status,
                'exports' => $book->exports_count,
                'product' => $book->product ? [
                    'id' => $book->product->id,
                    'on_sale' => $book->product->isPublished(),
                    'taken_down' => $book->product->isTakenDown(),
                ] : null,
                'open_reports' => (int) ($reported[$book->product?->id] ?? 0),
                'created_at' => $book->created_at?->toIso8601String(),
                'updated_at' => $book->updated_at?->toIso8601String(),
            ]),
            'counts' => [
                'books' => Book::count(),
                'deleted' => Book::onlyTrashed()->count(),
                'on_sale' => Product::published()->count(),
                'taken_down' => Product::whereNotNull('taken_down_at')->count(),
            ],
        ]);
    }

    /** Storefront listings and the author pages they make. */
    public function listings(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $state = (string) $request->query('state', '');

        $products = Product::query()
            ->with(['book' => fn ($b) => $b->withTrashed()->select('id', 'title', 'author_name', 'deleted_at'), 'user:id,name,email,store_handle'])
            ->withCount(['orderItems as sold' => fn ($i) => $i->whereHas('order', fn ($o) => $o->whereNotNull('paid_at'))])
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(fn ($w) => $w->where('slug', 'ilike', $like)->orWhere('title', 'ilike', $like)
                    ->orWhereHas('book', fn ($b) => $b->where('title', 'ilike', $like)));
            })
            ->when($state === 'on_sale', fn ($query) => $query->published())
            ->when($state === 'draft', fn ($query) => $query->whereNull('published_at')->whereNull('taken_down_at'))
            ->when($state === 'taken_down', fn ($query) => $query->whereNotNull('taken_down_at'))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $reports = ContentReport::where('status', 'open')
            ->whereIn('product_id', $products->getCollection()->pluck('id'))
            ->select('product_id', DB::raw('count(*) as n'))
            ->groupBy('product_id')
            ->pluck('n', 'product_id');

        $authors = User::whereNotNull('store_handle')
            ->whereHas('products', fn ($p) => $p->published())
            ->withCount(['products as on_sale' => fn ($p) => $p->published()])
            ->orderByDesc('on_sale')
            ->limit(50)
            ->get(['id', 'name', 'email', 'store_handle'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'handle' => $u->store_handle,
                'on_sale' => $u->on_sale,
                'url' => route('store.author', $u->store_handle),
            ]);

        return Inertia::render('Admin/Trust/Catalog', [
            'tab' => 'listings',
            'filters' => compact('q', 'state'),
            'listings' => $products->through(fn (Product $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->displayTitle(),
                'book_id' => $p->book_id,
                'book_deleted' => (bool) $p->book?->trashed(),
                'account' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
                'price_cents' => $p->price_cents,
                'currency' => $p->currency,
                'formats' => $p->formats,
                'state' => $p->isTakenDown() ? 'taken_down' : ($p->isPublished() ? 'on_sale' : 'draft'),
                'taken_down_at' => $p->taken_down_at?->toIso8601String(),
                'taken_down_reason' => $p->taken_down_reason,
                'sold' => (int) $p->sold,
                'open_reports' => (int) ($reports[$p->id] ?? 0),
                'url' => route('store.product', $p->slug),
            ]),
            'authors' => $authors,
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    public function takeDown(Request $request, Product $product, Moderation $moderation): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:1000'],
            'report_id' => ['nullable', 'integer'],
        ]);

        $report = isset($data['report_id']) ? ContentReport::where('product_id', $product->id)->find($data['report_id']) : null;
        $moderation->takeDown($product, $data['reason'], $request->user(), $report);

        return back()->with('platform_notice', 'Listing taken down. The author has been told; buyers keep their copies.');
    }

    public function restore(Request $request, Product $product, Moderation $moderation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:1000']]);
        $moderation->restore($product, $data['reason'], $request->user());

        return back()->with('platform_notice', 'Listing restored and the author told.');
    }
}
