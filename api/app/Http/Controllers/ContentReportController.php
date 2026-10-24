<?php

namespace App\Http\Controllers;

use App\Commerce\Commerce;
use App\Models\ContentReport;
use App\Models\Product;
use App\Models\User;
use App\Platform\Features;
use App\Platform\Trust\Moderation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Report this book" on a public storefront page. No account needed;
 * rate-limited per IP (`content-reports`). The report goes to the console's
 * moderation queue; nothing happens to the listing until staff look.
 */
class ContentReportController extends Controller
{
    public function product(Request $request, Product $product, Moderation $moderation): RedirectResponse
    {
        abort_unless(Commerce::enabled() && $product->isPublished(), 404);
        $product->loadMissing('user');
        abort_unless(Features::enabled('storefront', $product->user), 404);

        $moderation->report($product, null, $this->validated($request), $request);

        return back()->with('platform_notice', 'Thank you. Écrivain will look at this book.');
    }

    public function author(Request $request, string $handle, Moderation $moderation): RedirectResponse
    {
        abort_unless(Commerce::enabled() && User::where('store_handle', $handle)->exists(), 404);

        $moderation->report(null, $handle, $this->validated($request), $request);

        return back()->with('platform_notice', 'Thank you. Écrivain will look at this page.');
    }

    /** @return array{category: string, details: ?string, email: ?string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(ContentReport::CATEGORIES))],
            'details' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            // A field people never see: a form filled in by a script fills it.
            'website' => ['nullable', 'max:0'],
        ]);

        return ['category' => $data['category'], 'details' => $data['details'] ?? null, 'email' => $data['email'] ?? null];
    }
}
