<?php

/*
|--------------------------------------------------------------------------
| Direct sales (storefront, checkout, fulfilment, royalties)
|--------------------------------------------------------------------------
|
| An author turns a book into a product, readers buy it on a public
| storefront through Stripe Checkout, and a signed webhook fulfils the order
| with a license and expiring download links. See App\Commerce.
|
| The feature is ON when Stripe keys are configured and OFF otherwise.
| COMMERCE_ENABLED overrides that in either direction; locally it is how the
| storefront is tried end to end against the fake gateway, which never talks
| to Stripe. The fake gateway is refused in production (App\Commerce\Commerce
| ::enabled), so a stray flag cannot hand out books without payment.
|
*/

$stripeConfigured = filled(env('STRIPE_SECRET'));

return [

    'enabled' => filter_var(env('COMMERCE_ENABLED', $stripeConfigured), FILTER_VALIDATE_BOOL),

    // 'stripe' or 'fake'. Defaults to the real one only when it can work.
    'gateway' => env('COMMERCE_GATEWAY', $stripeConfigured ? 'stripe' : 'fake'),

    // ISO 4217, lower case as Stripe writes them.
    'currencies' => ['usd', 'eur', 'cad', 'gbp'],

    // What a product may deliver: formats the export system builds as files
    // a reader can open. Keys of App\Models\BookExport::FORMATS.
    'formats' => ['epub', 'pdf'],

    // Stripe's standard card pricing, used to ESTIMATE the fee on each sale.
    // The real fee is on the balance transaction, which would cost a Stripe
    // call per order to read; reports label the figure as an estimate.
    'stripe_fee' => [
        'percent' => (float) env('COMMERCE_STRIPE_FEE_PERCENT', 2.9),
        'fixed_cents' => (int) env('COMMERCE_STRIPE_FEE_FIXED_CENTS', 30),
    ],

    // The platform's share of what is left after the payment fee. Zero until
    // pricing is decided (an owner decision, like credit costs).
    'platform_rate' => (float) env('COMMERCE_PLATFORM_RATE', 0),

    // How long an emailed download link works. Buyers who were signed in at
    // checkout can mint fresh ones from /purchases at any time.
    'download_link_hours' => (int) env('COMMERCE_DOWNLOAD_LINK_HOURS', 72),

    // The licenses an author can attach to a product, and their wording.
    // The text is copied onto each license when it is issued, so changing it
    // here never rewrites what an earlier buyer agreed to.
    'licenses' => [
        'personal' => [
            'label' => 'Personal use',
            'terms' => 'For your personal reading on your own devices. Please do not share the files or the download links.',
        ],
        'household' => [
            'label' => 'Household',
            'terms' => 'For reading by the members of one household on their own devices. Please do not share the files outside it.',
        ],
        'classroom' => [
            'label' => 'Classroom',
            'terms' => 'For one teacher and the students of one class, for teaching. Please do not publish the files or share them beyond the class.',
        ],
    ],

    // Paperbacks printed and shipped by Lulu (App\Commerce\Print). Lulu bills
    // in US dollars, so a paperback sells in USD only, to the countries
    // listed, for its own price plus this flat shipping charge. The charge is
    // the owner's decision and has no default: with none set, no paperback is
    // offered. Lulu's real shipping cost depends on the address and level.
    'paperback' => [
        'currency' => 'usd',
        'shipping_cents' => filled(env('COMMERCE_PAPERBACK_SHIPPING_CENTS')) ? (int) env('COMMERCE_PAPERBACK_SHIPPING_CENTS') : null,
        'countries' => array_values(array_filter(array_map('trim', explode(',', (string) env('COMMERCE_PAPERBACK_COUNTRIES', 'US'))))),
    ],

];
