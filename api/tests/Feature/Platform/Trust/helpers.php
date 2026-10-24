<?php

use App\Commerce\Downloads;
use App\Models\Book;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Shared by the Trust & operations tests: a book on sale with a file a
 * buyer can download, and a paid order with its licence, written directly
 * (the checkout itself is CommerceTest's business).
 */

function trustOnSale(?User $seller = null, array $book = []): Product
{
    $seller ??= User::factory()->create();
    $book = Book::factory()->for($seller)->create($book + ['title' => 'Salt and Iron']);

    $path = "exports/book-{$book->id}/salt.epub";
    Storage::disk('files')->put($path, 'epub bytes');
    $book->exports()->create(['format' => 'epub', 'status' => 'ready', 'path' => $path, 'byte_size' => 10]);

    $product = new Product(['price_cents' => 1200, 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal', 'published_at' => now()->subDay()]);
    $product->user_id = $seller->id;
    $product->book_id = $book->id;
    $product->setRelation('book', $book);
    $product->save();

    return $product->fresh();
}

/** @return array{0: Order, 1: License} */
function trustPaidOrder(Product $product, ?User $buyer = null): array
{
    $order = (new Order)->forceFill([
        'number' => 'ECR-'.Str::upper(Str::random(6)),
        'status' => 'paid',
        'buyer_user_id' => $buyer?->id,
        'buyer_email' => $buyer?->email ?? 'reader@example.com',
        'buyer_name' => 'A Reader',
        'buyer_country' => 'CA',
        'currency' => 'usd',
        'total_cents' => $product->price_cents,
        'paid_at' => now(),
    ]);
    $order->save();

    $item = (new OrderItem)->forceFill([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'book_id' => $product->book_id,
        'seller_id' => $product->user_id,
        'title' => $product->displayTitle(),
        'formats' => $product->formats,
        'license_type' => 'personal',
        'price_cents' => $product->price_cents,
    ]);
    $item->save();

    $license = (new License)->forceFill([
        'order_item_id' => $item->id,
        'key' => Str::random(32),
        'license' => 'personal',
        'terms' => 'Personal use.',
        'status' => 'active',
    ]);
    $license->save();

    return [$order, $license];
}

function trustDownloadUrl(License $license, string $format = 'epub'): string
{
    $license->load('item');

    return Downloads::links($license)[array_search($format, $license->item->formats, true)]['url'];
}
