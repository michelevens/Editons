<?php

/*
|--------------------------------------------------------------------------
| Publishing
|--------------------------------------------------------------------------
|
| Rendering switches first, then the print-on-demand and store figures the
| print kit uses (ADR "Print-on-demand kit"): cover wraps, print checks,
| the price and royalty calculator and store readiness.
|
| Every figure below is what the service publishes, read on the date in
| `checked` from the page in the comment beside it; docs/POD-SPECS.md has
| the quotes. Nothing here is a guess: where a service does not publish a
| figure (IngramSpark's spine caliper and print cost, for example) it is
| absent, and the screen says so rather than inventing one. Stores change
| these; when one does, change it here and in docs/POD-SPECS.md.
|
*/

return [
    // Node typesets equations for the exports (resources/node/math.cjs).
    'node_binary' => env('NODE_BINARY', 'node'),

    // Seconds one MathJax run may take (a chapter's equations at once). A
    // loaded machine starts Node slowly; past this, equations print as LaTeX.
    'math_timeout' => (int) env('MATH_TIMEOUT', 60),

    // Word (.docx) import, App\Import\DocxImporter. On by default; the flag
    // is there to switch it off without a deploy if a file type misbehaves.
    'docx_import' => (bool) env('DOCX_IMPORT', true),

    // The date every figure below was read from its source.
    'checked' => '2026-10-23',

    // A picture printed below this many dots per inch looks soft on paper
    // (KDP cover guide: "a minimum resolution of 300 DPI"; IngramSpark
    // File Creation Guide: 300 ppi for grayscale images).
    'print_dpi' => 300,

    /*
    | Print services. Lengths are inches. Paper keys: white, cream. Ink keys:
    | bw, standard_color, premium_color.
    */
    'print' => [

        'kdp' => [
            'label' => 'Amazon KDP',
            // Cover: https://kdp.amazon.com/en_US/help/topic/G201953020
            // "add 0.125" (3.2 mm) to the top, bottom, and outside edges of your cover."
            'bleed' => 0.125,
            // "page count x 0.002252"" (white), "page count x 0.0025"" (cream),
            // Premium Color 0.002347, Standard Color 0.002252.
            'spine_per_page' => [
                'white' => 0.002252,
                'cream' => 0.0025,
                'standard_color' => 0.002252,
                'premium_color' => 0.002347,
            ],
            // "a minimum page count of 79 pages to include text on the spine."
            'spine_text_min_pages' => 79,
            // "at least 0.0625" (1.6 mm) of space between the text and the edge of the spine."
            'spine_text_margin' => 0.0625,
            // https://kdp.amazon.com/en_US/help/topic/GGE5T76TWKA85DJM: "a 2" (50.8 mm) by
            // 1.2" (30.5 mm) white box", "in the lower right-hand corner of your book's back
            // cover"; https://kdp.amazon.com/en_US/help/topic/G5HDYGP4BXLX4RUW: "at least .25"
            // (6.3 mm) from the spine and trim of the cover".
            'barcode' => ['width' => 2.0, 'height' => 1.2, 'inset' => 0.25],
            // Interior: https://kdp.amazon.com/en_US/help/topic/GVBQ3CMEQW3W2VL6
            // Inside (gutter) margin by page count, as [up to this many pages, inches].
            'gutter' => [[150, 0.375], [300, 0.5], [500, 0.625], [700, 0.75], [828, 0.875]],
            // "at least 0.25 in (6.4 mm)" without bleed; interiors made here have none.
            'outside_margin' => 0.25,
            // Paperback page ranges by paper and ink.
            'pages' => [
                'white' => [24, 828],
                'cream' => [24, 776],
                'standard_color' => [72, 600],
                'premium_color' => [24, 828],
            ],
            // Trim sizes from the same page that the Design screen offers, and the
            // lower maximum 8.5 x 11 carries ("24-590 pages").
            'trims' => ['5 x 8 in', '5.5 x 8.5 in', '6 x 9 in', '7 x 10 in', '8.5 x 11 in'],
            'trim_max_pages' => ['8.5 x 11 in' => 590],
            // "more than 6.12 inches (155.5 mm) in width or more than 9 inches (228.6 mm) in height"
            'large_trim' => ['width' => 6.12, 'height' => 9.0],
            // https://kdp.amazon.com/en_US/help/topic/G201834340
            // "Fixed cost + (page count * per page cost) = printing cost". Amazon.com, USD.
            // Below `flat_below` pages the flat figure applies. KDP's table lists black ink
            // as "24-110" and "110-828"; at 110 both rows apply and the formula is used.
            'print_cost' => [
                'currency' => 'USD',
                'bw' => [
                    'regular' => ['flat_below' => 110, 'flat' => 2.30, 'fixed' => 1.00, 'per_page' => 0.012],
                    'large' => ['flat_below' => 110, 'flat' => 2.84, 'fixed' => 1.00, 'per_page' => 0.017],
                ],
                'premium_color' => [
                    'regular' => ['flat_below' => 42, 'flat' => 3.60, 'fixed' => 1.00, 'per_page' => 0.065],
                    'large' => ['flat_below' => 42, 'flat' => 4.20, 'fixed' => 1.00, 'per_page' => 0.08],
                ],
                'standard_color' => [
                    'regular' => ['flat_below' => 0, 'flat' => 0.0, 'fixed' => 1.00, 'per_page' => 0.0255],
                    'large' => ['flat_below' => 0, 'flat' => 0.0, 'fixed' => 1.00, 'per_page' => 0.0402],
                ],
            ],
            // https://kdp.amazon.com/en_US/help/topic/G201834330
            // "(Royalty rate x list price) – printing costs = royalty". Amazon.com: 50% at or
            // below $9.98, 60% at or above $9.99. Expanded Distribution: 40%.
            'royalty' => ['threshold' => 9.99, 'below' => 0.50, 'at_or_above' => 0.60, 'expanded' => 0.40],
            // https://kdp.amazon.com/en_US/help/topic/G201834170
            // "Free ISBNs from us can only be used on KDP."
            'free_isbn' => 'store_only',
            'source' => 'https://kdp.amazon.com/en_US/help/topic/G201834340',
        ],

        'ingram' => [
            'label' => 'IngramSpark',
            // File Creation Guide, dated 8.24.26:
            // https://www.ingramspark.com/hubfs/downloads/file-creation-guide.pdf
            // "BLEED: 0.125" (3 mm) bleed is required on all four sides."
            'bleed' => 0.125,
            // No per-page caliper is published: IngramSpark gives spine widths only
            // through its Cover Template Generator, so the author enters that width.
            'spine_per_page' => null,
            // "NO spine text allowed for Perfect Bound books with page counts below 48."
            'spine_text_min_pages' => 48,
            // Spine safety: "0.0625" (2 mm) left/right sides for spines 0.35" and larger",
            // "0.03125" (1 mm) ... for spines smaller than 0.35"".
            'spine_text_margin' => 0.0625,
            'spine_text_margin_narrow' => ['below' => 0.35, 'margin' => 0.03125],
            // "please leave an area of at least 1.75" wide x 1" high"; "0.25" (6 mm)
            // recommended margin on all sides from final trim size".
            'barcode' => ['width' => 1.75, 'height' => 1.0, 'inset' => 0.25],
            // Interior: "Margins: Recommended minimum 0.5" (13 mm) on all sides."
            'gutter' => [[PHP_INT_MAX, 0.5]],
            'outside_margin' => 0.5,
            // Trim Size Matrix, black and white: 50lb creme "18-1050 pgs", 50lb white
            // "18-1200 pgs". Read from a table in the PDF; the matrix is the authority.
            'pages' => [
                'white' => [18, 1200],
                'cream' => [18, 1050],
            ],
            'trims' => ['5 x 8 in', '5.5 x 8.5 in', '6 x 9 in', '7 x 10 in', '8.5 x 11 in'],
            'trim_max_pages' => [],
            // No public print-cost formula: IngramSpark points to its Print & Ship
            // Calculator (https://www.ingramspark.com/pricing).
            'print_cost' => null,
            // https://www.ingramspark.com/blog/why-should-i-discount-my-book
            // "range of 40% (minimum) to 55% (maximum)" wholesale discount in the US.
            // User Guide v3.3: list price - wholesale discount - print cost = compensation.
            'discount' => ['min' => 0.40, 'max' => 0.55],
            // https://www.ingramspark.com/free-isbn: "free ISBNs are only available in the
            // U.S." and "will only be able to be associated with your title on IngramSpark."
            'free_isbn' => 'us_only',
            'source' => 'https://www.ingramspark.com/hubfs/downloads/file-creation-guide.pdf',
        ],

        'lulu' => [
            'label' => 'Lulu',
            // Book Creation Guide:
            // https://assets.lulu.com/media/guides/en/lulu-book-creation-guide.pdf
            // "Lulu requires a 0.125 in/3.175 mm bleed margin for all files."
            'bleed' => 0.125,
            // Paperback: "(# of Interior Pages / 444) + 0.06 in" — the 444 PPI white paper.
            'spine_formula' => ['pages_per_inch' => 444, 'plus' => 0.06],
            'spine_papers' => ['white'],
            // "If your book is 80 pages or fewer, do not include spine text."
            'spine_text_min_pages' => 81,
            // "at least 0.125 in/3.175 mm between the spine text and the edge of the spine"
            'spine_text_margin' => 0.125,
            // No published barcode box was found, so none is drawn.
            'barcode' => null,
            // Cover wrap only: Lulu's interior rules are not encoded yet.
            'checks' => false,
            'source' => 'https://assets.lulu.com/media/guides/en/lulu-book-creation-guide.pdf',
        ],
    ],

    /*
    | eBook stores.
    */
    'ebook' => [
        // https://kdp.amazon.com/en_US/help/topic/G200634560: "Effective July 7, 2026, the
        // 70% royalty option price band is $2.99-$12.99 on Amazon.com". 35% option minimums
        // by file size: under 3 MB $0.99, 3 MB to under 10 MB $1.99, 10 MB or more $2.99;
        // maximum $200.00. https://kdp.amazon.com/en_US/help/topic/G200634500: delivery
        // "US $0.15/MB", minimum US$0.01; the 70% option needs a list price "at least 20%
        // below the list price on Amazon" of the physical book.
        'amazon_kdp' => [
            'band' => [2.99, 12.99],
            'high' => 0.70,
            'low' => 0.35,
            'delivery_per_mb' => 0.15,
            'delivery_min' => 0.01,
            'min_price_by_mb' => [[3, 0.99], [10, 1.99], [PHP_INT_MAX, 2.99]],
            'max_price' => 200.00,
            'below_print' => 0.20,
            'source' => 'https://kdp.amazon.com/en_US/help/topic/G200634560',
        ],
        // https://authors.apple.com/: "70% royalties on every ebook, regardless of price".
        'apple_books' => ['rate' => 0.70, 'source' => 'https://authors.apple.com/'],
        // Kobo Writing Life service agreement: 70% when the price is at least $2.99 (US) and
        // at least 20% below the price of every physical edition; otherwise 45%.
        'kobo' => [
            'rate' => 0.70,
            'low' => 0.45,
            'min_for_rate' => 2.99,
            'below_print' => 0.20,
            'source' => 'https://cdn.kobo.com/merch-assets/writinglife/KOBO/en-US/serviceAgreement.html',
        ],
        // https://support.google.com/books/partner/answer/9331459: "a 70% revenue split on
        // ebook sales in most Google Play Books supported countries" once the updated terms
        // are accepted; 52% before that and in the countries the page lists.
        'google_play' => [
            'rate' => 0.70,
            'default' => 0.52,
            'source' => 'https://support.google.com/books/partner/answer/9331459',
        ],

        /*
        | What each store needs from a listing.
        | Cover: KDP "at least 500 pixels on the longest side"
        | (https://kdp.amazon.com/en_US/help/topic/G6GTK3T3NUHKLEFX); Apple "at least 1400
        | pixels along the shorter axis" (Apple Books Asset Guide); Google "a minimum
        | resolution of 640 pixels" (support.google.com/books/partner/answer/3424254); Kobo
        | publishes no minimum. Description: KDP "a limit of 4000 characters"
        | (G201189630); Apple "at least 50 characters" (itunespartner metadata). Keywords:
        | KDP up to seven (G201298500).
        */
        'listing' => [
            'amazon_kdp' => ['cover_min_long' => 500, 'description_max' => 4000, 'keywords_max' => 7],
            'apple_books' => ['cover_min_short' => 1400, 'description_min' => 50],
            'kobo' => [],
            'google_play' => ['cover_min_short' => 640],
        ],
    ],

    /*
    | ISBN agencies, by country of publication (the book's publishing
    | details). Official agency pages only; no fees are quoted here, because
    | none was read from an agency's own price list except France's, which
    | changes (https://www.afnil.org/tarification).
    */
    'isbn_agencies' => [
        // Bowker is the US agency in the International ISBN Agency directory;
        // its own page (myidentifiers.com) refused our reader, so check it there.
        'US' => ['agency' => 'Bowker (MyIdentifiers)', 'url' => 'https://www.myidentifiers.com', 'free' => false],
        // "ISBN Canada provides free ISBNs to eligible publishers and publications."
        'CA' => ['agency' => 'ISBN Canada (Library and Archives Canada)', 'url' => 'https://www.canada.ca/en/library-archives/services/publishers/isbn.html', 'free' => true],
        // AFNIL serves France, Belgium and francophone Africa; "des frais de gestion
        // de dossier s'appliquent".
        'FR' => ['agency' => 'AFNIL', 'url' => 'https://www.afnil.org', 'free' => false],
        'GB' => ['agency' => 'Nielsen ISBN Agency for UK & Ireland', 'url' => 'https://nielseniq.com/global/en/landing-page/nielseniq-bookdata-isbn-agency', 'free' => false],
        // Haiti is listed in the International ISBN Agency's directory; no page
        // of the agency itself could be confirmed, so the directory is the link.
        'HT' => ['agency' => 'the agency for Haiti in the International ISBN Agency directory', 'url' => 'https://www.isbn-international.org/agencies', 'free' => null],
        'default' => ['agency' => "your country's agency in the International ISBN Agency directory", 'url' => 'https://www.isbn-international.org/agencies', 'free' => null],
    ],

    /*
    | Legal deposit, by country of publication. Shown as a reminder; the
    | law is the authority, and each entry links to where it was read.
    */
    'legal_deposit' => [
        // BNH notice, Le Nouvelliste, 8 Dec 2011 (a notice issued by the library):
        // "décret du 20 octobre 1984", art. 18 "Les documents doivent être déposés
        // en cinq (5) exemplaire[s]". Other sources date the decree 15 October 1984.
        'HT' => [
            'copies' => 5,
            'to' => "the Bibliothèque nationale d'Haïti",
            'basis' => 'the decree of October 1984 on legal deposit',
            'when' => null,
            'source' => 'https://lenouvelliste.com/article/100176/note-informative-de-la-bibliotheque-nationale-dhaiti',
        ],
        // https://www.bnf.fr/node/255: "un exemplaire de chacun des documents
        // imprimés qu'il édite, au plus tard le jour de leur mise en circulation."
        'FR' => [
            'copies' => 1,
            'to' => 'the Bibliothèque nationale de France',
            'basis' => 'the Code du patrimoine',
            'when' => 'by the day the book goes on sale',
            'source' => 'https://www.bnf.fr/node/255',
        ],
        // https://www.copyright.gov/mandatory/: "two copies of each work published in
        // the United States", "within three months of publication", the best edition.
        'US' => [
            'copies' => 2,
            'to' => 'the U.S. Copyright Office',
            'basis' => 'mandatory deposit',
            'when' => 'within three months of publication',
            'source' => 'https://www.copyright.gov/mandatory/',
        ],
        // LAC: "2 copies" when 100 or more are produced, "1 copy" for 4 to 99.
        'CA' => [
            'copies' => 2,
            'to' => 'Library and Archives Canada',
            'basis' => 'the Legal Deposit of Publications Regulations',
            'when' => 'one copy instead when 4 to 99 copies are produced',
            'source' => 'https://www.canada.ca/en/library-archives/services/publishers/legal-deposit/physical-publications.html',
        ],
    ],

    /*
    | KDP's AI content questions: https://kdp.amazon.com/en_US/help/topic/G200672390
    | "We require you to inform us of AI-generated content (text, images, or
    | translations)". AI-generated: "text, images, or translations created by an
    | AI-based tool". AI-assisted (you created it and "used AI-based tools to edit,
    | refine, error-check, or otherwise improve that content"): "It is not necessary
    | to inform us of the use of such tools or processes."
    */
    'ai_disclosure' => [
        'source' => 'https://kdp.amazon.com/en_US/help/topic/G200672390',
        'categories' => ['text', 'images', 'translations'],
        'answers' => ['none', 'assisted', 'generated'],
    ],

    /*
    | Publish in three steps (docs/PUBLISHING-FLOW.md). What each channel's
    | own form asks for, read on 2026-10-25 from the pages beside each value.
    */
    'publish' => [
        'checked' => '2026-10-25',

        // Amazon KDP has no public publishing API: the guided page mirrors its
        // forms and the author pastes each value in.
        'kdp' => [
            // Opens the author's Bookshelf once signed in.
            'url' => 'https://kdp.amazon.com/',
            // G201097560: "Your title and subtitle together must be fewer than
            // 200 characters."
            'title_subtitle_max' => 199,
            // G201189630: "a limit of 4000 characters for the description",
            // HTML tags included; <h1>-<h3> are not supported.
            'description_max' => 4000,
            'description_tags' => ['p', 'br', 'b', 'em', 'i', 'u', 'h4', 'h5', 'h6', 'ol', 'ul', 'li'],
            // G201298500: "Use up to seven keywords or short phrases. Keep an eye
            // on the character limit in the text field." The page does not give
            // the number; 50 is what the form's boxes take according to
            // third-party guides (UNVERIFIED on an Amazon page).
            'keywords' => 7,
            'keyword_chars' => 50,
            'keyword_chars_verified' => false,
            // G200652170: "you can select 3 categories when creating a book in KDP."
            'categories' => 3,
            // G200673300, the languages KDP supports. Haitian Creole is not one.
            'languages' => ['en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch'],
            // An ASIN is ten capital letters and digits; a Kindle ASIN starts
            // with B, and a paperback with an ISBN has its ISBN-10 as ASIN.
            'asin' => '/^(B[0-9A-Z]{9}|[0-9]{9}[0-9X])$/',
            'sources' => [
                'title' => 'https://kdp.amazon.com/en_US/help/topic/G201097560',
                'description' => 'https://kdp.amazon.com/en_US/help/topic/G201189630',
                'keywords' => 'https://kdp.amazon.com/en_US/help/topic/G201298500',
                'categories' => 'https://kdp.amazon.com/en_US/help/topic/G200652170',
                'languages' => 'https://kdp.amazon.com/en_US/help/topic/G200673300',
                'ai' => 'https://kdp.amazon.com/en_US/help/topic/G200672390',
            ],
        ],

        // Google Play Books has no upload API: Partner Center takes a book list
        // (one book per row, "Separated by tabs", "UTF-16 encoding") and the
        // files, named by ISBN. support.google.com/books/partner/answer/3289689
        // (Add multiple books), 3058975 (the columns), 3424254 (file names).
        // "If your books don't have unique identifiers (such as ISBNs), you
        // cannot add them to your book catalogue using a spreadsheet."
        'google_play' => [
            'url' => 'https://play.google.com/books/publish/',
            // The column headers, in the order the help page lists them.
            'columns' => [
                'Identifier', 'Status (Do Not Modify)', 'Label (Do Not Modify)', 'Play Store Link (Do Not Modify)',
                'Enable for Sale?', 'Title', 'Subtitle', 'Book Format',
                'Related Identifier [Format, Relationship], Semicolon-Separated',
                'Contributor [Role], Semicolon-Separated', 'Biographical Note', 'Language',
                'Subject Code [Schema], Semicolon-Separated', 'Age Group, Comma-Separated', 'Description',
                'Publication Date', 'Page Count', 'Series Name', 'Volume in Series', 'Preview Type',
                'Preview Territories', 'Buy Link Text', 'Buy Link', 'Publisher Name', 'Publisher Website',
                'Show Photos in Preview?', 'PDF Download Allowed?', 'On Sale Date', 'DRM Enabled?',
                'Show Photos in eBook?', 'Include Scanned Pages?', 'For Mature Audiences?', 'Copy-Paste Percentage',
            ],
            // "Three-letter ISO 639-2/B code".
            'languages' => ['en' => 'eng', 'fr' => 'fre', 'ht' => 'hat', 'es' => 'spa', 'de' => 'ger', 'pt' => 'por'],
            'sources' => [
                'bulk' => 'https://support.google.com/books/partner/answer/3289689',
                'columns' => 'https://support.google.com/books/partner/answer/3058975',
                'files' => 'https://support.google.com/books/partner/answer/3424254',
            ],
        ],

        // Apple Books delivers through Transporter with a publisher account,
        // which the platform does not have yet: shown as coming soon.
        'apple_books' => ['available' => false],
    ],

    /*
    | Lulu Print API product codes (pod_package_id), from the API reference
    | (https://api.lulu.com/api-docs/openapi-specs/openapi_public.yml, read
    | 2026-10-25): "[Trim].[Ink].[Quality].[Binding].[Paper].[Finish]", e.g.
    | 0600X0900.BW.STD.PB.060UW444.MXX, a 6 x 9 in black-and-white standard
    | quality paperback on 60# white paper with a matte cover. The dotted form
    | is live since 31 March 2026; the 27-character form ends 1 February 2027.
    | Lulu's own file validation is the final word on whether a code fits.
    */
    'lulu_packages' => [
        'trims' => [
            '5 x 8 in' => '0500X0800', '5.5 x 8.5 in' => '0550X0850', '6 x 9 in' => '0600X0900',
            '7 x 10 in' => '0700X1000', '8.5 x 11 in' => '0850X1100',
        ],
        // ink => [colour, quality]
        'ink' => ['bw' => ['BW', 'STD'], 'standard_color' => ['FC', 'STD'], 'premium_color' => ['FC', 'PRE']],
        // Paper by ink and paper: 60# uncoated white or cream (444 ppi), and
        // 80# coated white for premium colour, as in the reference's examples.
        'paper' => ['bw' => ['white' => '060UW444', 'cream' => '060UC444'], 'standard_color' => ['white' => '060UW444'], 'premium_color' => ['white' => '080CW444']],
        'binding' => 'PB',
        // Cover coating, then no linen and no foil.
        'finish' => ['matte' => 'MXX', 'glossy' => 'GXX'],
    ],
];
