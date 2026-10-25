<?php

namespace App\Publishing\Publish;

use App\Commerce\Listing;
use App\Models\Book;
use App\Models\Product;
use App\Models\User;
use App\Platform\Features;
use App\Platform\House\HouseCatalog;
use Illuminate\Validation\ValidationException;

/**
 * Step 3 for our storefront: put the book on sale in one click.
 *
 * The product the Sales & Distribution pane edits, under the same rule
 * (Commerce\Listing: a price Stripe accepts, a book not archived or taken
 * down, a ready export of every format sold). A book with no product yet
 * gets one at its list price in US dollars, selling each format that has a
 * ready file, under the personal licence; the author changes any of it in
 * Sales & Distribution. A house book goes through the House catalog's own
 * actions, so the change is audited with the staff member as the actor.
 */
class StoreListing
{
    public function __construct(private readonly HouseCatalog $house) {}

    /** @throws ValidationException */
    public function publish(Book $book, User $actor): Product
    {
        if (! Features::enabled('storefront', $actor)) {
            throw ValidationException::withMessages(['store' => 'The storefront is switched off on this platform.']);
        }

        $product = $book->product()->first();

        if ($product?->isPublished()) {
            return $product;
        }

        if (! $product) {
            if ($book->list_price === null || (float) $book->list_price <= 0) {
                throw ValidationException::withMessages(['store' => 'Set the list price first.']);
            }

            $formats = array_values(array_filter(config('commerce.formats'), fn (string $f) => Listing::readyExport($book, $f) !== null));

            if ($formats === []) {
                throw ValidationException::withMessages(['store' => 'Generate the EPUB first: buyers download the latest export.']);
            }

            $cents = (int) round(((float) $book->list_price) * 100);
            $currency = config('commerce.paperback.currency', 'usd');

            if ($book->user?->isHouse()) {
                $product = $this->house->price($book, $cents, $currency, $formats, $actor, 'Publish: put on sale in the store');
            } else {
                Listing::assertSellable($book, $cents, $currency, $formats);

                $product = new Product;
                $product->fill([
                    'price_cents' => $cents,
                    'currency' => $currency,
                    'formats' => $formats,
                    'license' => array_key_first(config('commerce.licenses')),
                ]);
                // Ownership from the book, never from a request (ADR-0012).
                $product->user_id = $book->user_id;
                $product->book_id = $book->id;
                $product->setRelation('book', $book);
                $product->save();
            }
        }

        $book->setRelation('product', $product);

        if ($book->user?->isHouse()) {
            return $this->house->publish($book, $actor, 'Publish: put on sale in the store');
        }

        Listing::assertSellable($book, $product->price_cents, $product->currency, $product->formats);
        $product->forceFill(['published_at' => now()])->save();

        return $product;
    }
}
