# Storefront pricing: formats, sales, codes, bundles, pre-orders

How a book on the author's own storefront is priced, and what a buyer pays.
Decision record: `docs/DECISIONS.md`, "ADR-00XX — Per-format pricing, sales,
author discount codes, bundles and pre-orders". Code: `App\Commerce\Pricing`,
`App\Commerce\Bundles`, `App\Commerce\Preorders`, `App\Growth\Discounts`.

Nothing here changes a product nobody touches: it sells every format it
delivers at its one price, with no sale, straight away — exactly as before.

## Offers and per-format prices

A product is sold as **offers**:

| Offer | Delivers | Price |
|---|---|---|
| `all` | every format of the product | `price_cents` (the product's price) |
| `epub`, `pdf` | that one format | `format_prices[format]`, or `price_cents` when that format has none |
| `paperback` | a printed copy (Lulu) | `paperback_price_cents` |

Without format prices (`format_prices` null) the only digital offer is
`all`, and the Buy button buys it. With them, the book page lists each
format at its price, plus "All formats" at the product price when the
product has more than one format. A format the product does not deliver is
refused at checkout.

One currency per product, from `config('commerce.currencies')` (USD first,
so the default). Money is never converted.

## Sales

A sale is a lower price for one offer between two moments:
`products.sales = {offer: {cents, starts_at, ends_at}}`, stored in UTC and
entered in the seller's reporting timezone (`users.timezone`, else
`commerce.report_timezone`). Start inclusive, end exclusive. A sale price
must be below the offer's list price and at least Stripe's minimum.

The storefront shows the sale price with the usual price struck through and
"Sale ends …"; a shelf card shows the cheapest offer ("from …" when they
differ). **Checkout charges `Pricing::quote` at the moment the checkout
starts**: the order and the Stripe session are written from it, so a buyer
on Stripe's page when the sale ends still pays the price they were shown,
and nobody pays a price the page did not show. The order item keeps
`offer` and `list_price_cents`.

A discount code applies to the price after the sale.

## Discount codes

- **Site-wide** (the owner, console → Growth): `seller_id` null. Unchanged.
- **Author codes** (Sales page → Discount codes): `seller_id` = the author.
  Percent or fixed amount (in one currency), optional last day (end of that
  day in the author's zone), optional number of uses, optional list of the
  author's products and bundles (none: all of them). Storefront only, no
  Stripe coupon. Kind and value are fixed once made; the author can pause,
  resume, re-scope, change the limit and the last day.

Codes are unique per seller (`UNIQUE (COALESCE(seller_id, 0), upper(code))`),
so one author's SUMMER neither collides with nor reveals another's. At
checkout the buyer's code is looked up among the selling author's codes
first, then the site-wide ones; another author's code reads "That code is
not valid." Uses count paid orders plus checkouts open in the last two
hours, as before.

## Bundles

Several of one author's products at one price (Sales page → Bundles;
`/store/bundles/{slug}`). At least two, all the author's own, all in the
bundle's currency. On sale only while every product in it is on sale.

Buying a bundle writes **one order item per book**, each with its book,
seller, formats, licence type and `bundle_id`/`bundle_title`, so every book
gets its licence and downloads, and each book's royalty, statement, sales
report and CSV line stand on their own.

**Royalty rule.** The price actually paid (after any code) is split across
the books in proportion to their list prices (each product's whole-product
`price_cents`; sale prices ignored), in whole cents, the last book taking
the rounding remainder (`Revenue::allocate`), so the parts always add up to
what the buyer paid. Example: books at 10, 20 and 30 in a 45.00 bundle get
7.50, 15.00 and 22.50. The card fee is then split by the same shares (as for
any multi-item order), and each item's royalty is its share less its fee
part (less the platform rate). A refund is spread over the items the same
way; a full refund revokes every licence. No partner commission is due on a
bundle. The lines CSV has a `bundle` column.

## Pre-orders

A product's **release date** (`products.release_at`; entered as a day in the
seller's zone, released at its start). Until then:

- the page says "Pre-order: released on …" and the button "Pre-order now";
- the product may go on sale before its exports exist (they are needed at
  release; a missing one is rebuilt on the first download);
- checkout and payment work as usual; each item keeps `release_at`, gets no
  licence when paid, and the receipt says when the files arrive;
- a paperback cannot be pre-ordered (it would be printed at once).

`commerce:release-preorders` (every five minutes) issues the licences of
items whose date has come — once each, under a row lock — and sends
`PreorderReleasedMail` with the download links.

**Cancelling.** A signed-in buyer can cancel from Your purchases while
nothing in the order has been delivered: the whole order is refunded through
the console's refund path (`App\Billing\Refunds::refundOrder`, audited, with
the buyer as actor). With Stripe the order changes when `charge.refunded`
arrives; an order with a refund under way is never released. Buyers who
checked out signed out cancel through support (the console's refund).
