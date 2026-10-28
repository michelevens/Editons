# Print-on-demand and store specifications

What the print kit (ADR "Print-on-demand kit") relies on, as each service
publishes it. Every page below was read on **2026-10-23**; the trim sizes,
custom trim ranges and margin rules again on **2026-10-28** (ADR "Mirrored
margins, trims, typography and house styles"). The code takes its
figures from `api/config/publishing.php`, where each value has its source in a
comment. When a service changes a figure, change it there and here.

If a figure was not published, or we could not confirm it from the service's
own page, it is marked **not confirmed** and the code does not use it.

Quotes come from the official page or PDF. Most pages were read through a text
extractor, so check the wording on the page itself before quoting it
elsewhere.

## Amazon KDP: paperback

### Cover
Source: https://kdp.amazon.com/en_US/help/topic/G201953020

- **Bleed:** "add 0.125" (3.2 mm) to the top, bottom, and outside edges of your cover."
- **Width and height:** "Cover Width = Bleed + Back Cover Width + Spine Width + Front Cover Width + Bleed"; "Cover Height = Bleed + Trim Height + Bleed".
- **Spine width per page:**

  | Paper and ink | Spine per page |
  |---|---|
  | Black and white, white paper | 0.002252 in |
  | Black and white, cream paper | 0.0025 in |
  | Standard colour | 0.002252 in |
  | Premium colour | 0.002347 in |

- **Spine text:** "a minimum page count of 79 pages to include text on the spine". Text needs "at least 0.0625" (1.6 mm) of space between the text and the edge of the spine."
- **Resolution:** "a minimum resolution of 300 DPI".
- **Barcode box:** "a 2" (50.8 mm) by 1.2" (30.5 mm) white box", "in the lower right-hand corner of your book's back cover". Source: https://kdp.amazon.com/en_US/help/topic/GGE5T76TWKA85DJM
- **Barcode position:** "at least .25" (6.3 mm) from the spine and trim of the cover". Source: https://kdp.amazon.com/en_US/help/topic/G5HDYGP4BXLX4RUW

### Interior
Source: https://kdp.amazon.com/en_US/help/topic/GVBQ3CMEQW3W2VL6

- **Inside (gutter) margin by page count:**

  | Pages | Inside margin |
  |---|---|
  | 24–150 | 0.375 in |
  | 151–300 | 0.5 in |
  | 301–500 | 0.625 in |
  | 501–700 | 0.75 in |
  | 701–828 | 0.875 in |

- **Top, bottom and outside margins:** "The top, bottom, and outside margins must be a minimum of 0.25 inches for books without bleed and 0.375 inches (9.6mm) for books with bleed." Interiors made here have no bleed. PrintCheck fails a narrower one.
- **Inside margin:** "Make sure to input the same value for your inside margin and the gutter." The app never sets the inside below the band for the page count (it widens it and says why on Design & Layout).
- **Page ranges:**

  | Paper and ink | Pages |
  |---|---|
  | Black and white, white | 24–828 |
  | Black and white, cream | 24–776 |
  | Standard colour | 72–600 |
  | Premium colour | 24–828 |

- **Paperback trims** (the page's table "Trim size specifications (width x height) with minimum and maximum page counts", kdp.amazon.com, read 2026-10-28), black ink on white paper:

  | Trim (in) | cm | Pages | Large trim |
  |---|---|---|---|
  | 5 × 8 | 12.7 × 20.32 | 24–828 | |
  | 5.06 × 7.81 | 12.85 × 19.84 | 24–828 | |
  | 5.25 × 8 | 13.34 × 20.32 | 24–828 | |
  | 5.5 × 8.5 | 13.97 × 21.59 | 24–828 | |
  | 6 × 9 | 15.24 × 22.86 | 24–828 | |
  | 6.14 × 9.21 | 15.6 × 23.39 | 24–828 | yes |
  | 6.69 × 9.61 | 16.99 × 24.41 | 24–828 | yes |
  | 7 × 10 | 17.78 × 25.4 | 24–828 | yes |
  | 7.44 × 9.69 | 18.9 × 24.61 | 24–828 | yes |
  | 7.5 × 9.25 | 19.05 × 23.5 | 24–828 | yes |
  | 8 × 10 | 20.32 × 25.4 | 24–828 | yes |
  | 8.25 × 6 | 20.96 × 15.24 | 24–800 | yes |
  | 8.25 × 8.25 | 20.96 × 20.96 | 24–800 | yes |
  | 8.5 × 8.5 | 21.59 × 21.59 | 24–590 | yes |
  | 8.5 × 11 | 21.59 × 27.94 | 24–590 | yes |
  | 8.27 × 11.69 (A4) | 21 × 29.7 | 24–780 | yes |

  Cream paper allows fewer pages on several sizes (24–776, 24–750, 24–550, 24–730); the code keeps the white maximum per trim beside the paper's own range. The same page gives A4 premium colour as 24–590 in one table and 24–780 in another: **not confirmed**, not used. kdp.amazon.co.jp lists further sizes (5.83 × 8.27, 7.17 × 10.12 and others) that the US store does not; they are not on the KDP list here, though most fall inside the custom range below.
- **Custom trim:** "For paperbacks, you can also enter your own custom trim size. Width must be within 4"-8.5" (10.16-21.59 cm). Height must be within 6"-11.69" (15.24-29.69 cm)." Source: https://kdp.amazon.com/en_US/help/topic/G201834180. This is the range the Design screen accepts for a custom trim.
- **Large trim:** "more than 6.12 inches (155.5 mm) in width or more than 9 inches (228.6 mm) in height".

## Amazon KDP: hardcover
Sources: https://kdp.amazon.com/en_US/help/topic/GAVW3FZZAKA2KY3B and https://kdp.amazon.com/en_US/help/topic/GKYZRXFBZH2LDXAK

- **Trims:** "There are five available trim sizes": 5.5 × 8.5, 6 × 9, 6.14 × 9.21, 7 × 10 and 8.25 × 11 in.
- **Pages:** "The minimum and maximum page count is 75 - 550 pages".
- **Binding:** "We offer case laminate hardcover. Dust jackets, cloth bound, and other hardcover formats aren't available."
- **Custom trim:** no page offers one for hardcover; the custom wording is for paperbacks. **Inferred**, not stated.
- **Margins:** the hardcover formatting page links to the paperback margin table, so the same table applies.

### Printing cost, Amazon.com (USD)
Source: https://kdp.amazon.com/en_US/help/topic/G201834340

The formula is "Fixed cost + (page count * per page cost) = printing cost".

| Ink | Pages | Regular trim | Large trim |
|---|---|---|---|
| Black | 24–110 | $2.30 | $2.84 |
| Black | 110–828 | $1.00 + $0.012/page | $1.00 + $0.017/page |
| Premium colour | 24–40 | $3.60 | $4.20 |
| Premium colour | 42–828 | $1.00 + $0.065/page | $1.00 + $0.08/page |
| Standard colour | 72–600 | $1.00 + $0.0255/page | $1.00 + $0.0402/page |

At 110 pages both black-ink rows apply, and the code uses the formula. EUR and
GBP rows are on the same page but are not used yet.

### Paperback royalty
Source: https://kdp.amazon.com/en_US/help/topic/G201834330

- **Formula:** "(Royalty rate x list price) – printing costs = royalty".
- **Rate on Amazon.com:** 50% at or below $9.98, 60% at or above $9.99.
- **Expanded Distribution:** 40%.

### ISBN
Source: https://kdp.amazon.com/en_US/help/topic/G201834170

- "Free ISBNs from us can only be used on KDP. They cannot be used to publish outside of KDP."
- With a free ISBN, the imprint is "Independently published".
- "eBooks and low-content books are the only formats where an ISBN is optional."

## Amazon KDP: Kindle eBook

- **Royalty options:** 35% is "Royalty Rate x (List Price – applicable VAT)". 70% is "Royalty Rate x (List Price – applicable VAT - Delivery Costs)". Source: https://kdp.amazon.com/en_US/help/topic/G200644210
- **70% price band:** "Effective July 7, 2026, the 70% royalty option price band is $2.99-$12.99 on Amazon.com", up from $2.99–$9.99. Source: https://kdp.amazon.com/en_US/help/topic/G200634560
- **35% price limits on Amazon.com:** under 3 MB, $0.99–$200.00. From 3 MB to under 10 MB, $1.99–$200.00. 10 MB or more, $2.99–$200.00.
- **Delivery cost:** "US $0.15/MB", with a minimum of US$0.01. Source: https://kdp.amazon.com/en_US/help/topic/G200634500
- **Print price rule:** the 70% option needs a list price "at least 20% below the list price on Amazon" for the physical book.

### Listing
- **Cover:** "at least 500 pixels on the longest side". The recommended size is 2,560 × 1,600 px. Source: https://kdp.amazon.com/en_US/help/topic/G6GTK3T3NUHKLEFX
- **Description:** "a limit of 4000 characters". Source: https://kdp.amazon.com/en_US/help/topic/G201189630
- **Keywords:** up to seven. Source: https://kdp.amazon.com/en_US/help/topic/G201298500
- **Categories:** "you can select 3 categories". Source: https://kdp.amazon.com/en_US/help/topic/G200652170

### AI content
Source: https://kdp.amazon.com/en_US/help/topic/G200672390

- **What must be disclosed:** "We require you to inform us of AI-generated content (text, images, or translations)".
- **AI-generated** means "created by an AI-based tool".
- **AI-assisted** means you created the content and "used AI-based tools to edit, refine, error-check, or otherwise improve that content". For this, "It is not necessary to inform us of the use of such tools or processes."
- **Not confirmed:** the exact wording of the questions in the title-setup form. Only the guideline page was read.

## IngramSpark

### File Creation Guide (dated 8.24.26)
Source: https://www.ingramspark.com/hubfs/downloads/file-creation-guide.pdf

- **Cover bleed:** "0.125" (3 mm) bleed is required on all four sides."
- **Cover margin:** "0.25" (6 mm) recommended margin on all sides from final trim size".
- **Spine safety:** "0.0625" (2 mm) left/right sides for spines 0.35" and larger", and "0.03125" (1 mm) ... for spines smaller than 0.35"".
- **Spine text:** "NO spine text allowed for Perfect Bound books with page counts below 48."
- **Spine width:** no per-page figure is published. "IngramSpark highly recommend the use of our custom Cover Template Generator", with "a mod2 spine calculation (page count divisible by 2)". **The author enters the spine width the generator gives.** We do not estimate it.
- **Barcode:** "If you're unable to supply a barcode, please leave an area of at least 1.75" wide x 1" high".
- **Interior margins:** "Recommended minimum 0.5" (13 mm) on all sides." and "It is recommended that you provide a minimum of 0.5" (13 mm) margin on all sides of your text block. LS allows for 1/16" (0.0625 in / 2 mm) variance in printing." A recommendation, so PrintCheck warns (not fails) below it. There is **no gutter table by page count**.
- **Page ranges:** from the Trim Size Matrix, black and white: 50lb creme 18–1050 pages, 50lb white 18–1200 pages. These were read from a table in the PDF. The matrix is the authority for your trim.
- **Trims (Trim Size Matrix, p. 40, read 2026-10-28).** The matrix marks availability with shaded cells, not text; they were read by sampling the rendered page and checked row by row. The guide writes millimetres as height × width. Black and white on 50lb white, perfect bound:
  4 × 6, 4 × 7, 4.25 × 7, 4.37 × 7, 4.72 × 7.48, 5 × 7, 5 × 8, 5.06 × 7.81, 5.25 × 8, 5.5 × 7.5, 5.5 × 8.25, 5.5 × 8.5 (Demy 8vo), 5.83 × 8.27 (A5), 6 × 9, 6.14 × 9.21 (Royal 8vo), 6.5 × 6.5, 6.625 × 10.25, 6.69 × 9.61 (pinched crown), 7 × 10, 7.44 × 9.69 (crown quarto), 7.5 × 9.25, 8 × 8, 8 × 10, 8 × 10.88, 8.25 × 10.75, 8.25 × 11, 8.268 × 11.693 (A4, stored as 8.27 × 11.69), 8.5 × 8.5, 8.5 × 9, 8.5 × 11 in.
  Also on 50lb creme: 4 × 6, 4 × 7, 4.25 × 7, 4.72 × 7.48, 5 × 7, 5 × 8, 5.06 × 7.81, 5.25 × 8, 5.5 × 8.25, 5.5 × 8.38, 5.5 × 8.5, 5.83 × 8.27, 6 × 9, 6.14 × 9.21 (5.5 × 8.38 is creme and groundwood only). 11 × 8.5 is premium colour only ("Max 400 pages") and is left out.
- **Hardcover (case laminate) trims:** 5 × 8, 5.5 × 8.5, 5.83 × 8.27, 6 × 9, 6.14 × 9.21, 6.69 × 9.61, 7 × 10, 7.5 × 9.25, 8 × 8, 8 × 10, 8 × 10.88, 8.25 × 10.75, 8.5 × 8.5, 8.5 × 11 in. "Where available, Jacketed Covers can be set-up with either Case Laminate or Digital Cloth™ Covers."
- **Custom trims (p. 41):** "Custom trim sizes are available for Perfect Bound, Case Laminate and Dust Jacket books with size restrictions on the finished book". Perfect bound, white and groundwood: "Widths available from: 4" to 8.5" or 102mm to 216mm" and "Lengths available from: 6" to 11" or 152mm to 279mm"; crème: widths "4" to 6.14"", lengths "6" to 9.252" (152mm to 235mm)". Case laminate and dust jacket, white: widths "5" to 8.5"", lengths "7" to 11"; crème and groundwood: widths "5" to 6.14"", lengths "7" to 9.252"". PrintCheck uses the perfect-bound ranges.
- **Not confirmed:** the IngramSpark trim-size web page (https://www.ingramspark.com/plan-your-book/print/trim-sizes) gives the white hardcover minimum as 5 × 8 and the crème maximum as 6.14 × 9.21, where the guide says 5 × 7 and 9.252. The guide is used.

### Compensation
- **Discount range:** "range of 40% (minimum) to 55% (maximum)" in the US. Source: https://www.ingramspark.com/blog/why-should-i-discount-my-book
- **Worked example:** "$20.00 US List Price / -53% Wholesale Discount / $9.40 Wholesale Price / -$3.66 Print Cost / $5.74 Compensation earned and paid". Source: User Guide v3.3, https://www.ingramspark.com/hubfs/downloads/user-guide.pdf
- **Print cost:** **not published as a formula.** IngramSpark points to its Print & Ship Calculator at https://www.ingramspark.com/pricing. The calculator shows the wholesale price before print cost and says so.
- **Ebooks:** "You earn 85% of the net revenue received by IngramSpark with each sale". Net revenue is not published, so no per-copy figure is computed.

### ISBN
Source: https://www.ingramspark.com/free-isbn

- "free ISBNs are only available in the U.S."
- "Your free ISBN from IngramSpark will only be able to be associated with your title on IngramSpark."
- The help page on ISBN requirements refused our reader, so a broader requirement outside the US is **not confirmed**.

## Lulu
- **Cover dimensions:** the Print API has `POST /cover-dimensions/`, which takes `pod_package_id` and `interior_page_count`. Its example is a 6×9 book of 210 pages, which returns 920 × 666 pt. Source: https://api.lulu.com/api-docs/openapi-specs/openapi_public.yml
- **Sandbox:** `https://api.sandbox.lulu.com`. Authentication is OAuth2 client credentials. The kit does not call the API.
- **Paperback spine:** "(# of Interior Pages / 444) + 0.06 in", for 444 PPI white paper. Source: Book Creation Guide, https://assets.lulu.com/media/guides/en/lulu-book-creation-guide.pdf
- **Bleed:** "a 0.125 in/3.175 mm bleed margin for all files".
- **Spine text:** "If your book is 80 pages or fewer, do not include spine text." Leave "at least 0.125 in/3.175 mm between the spine text and the edge of the spine".
- **Cross-check:** for 6×9 and 210 pages, our formula gives 920.4 pt. The API example gives 920 pt.
- **Not confirmed:** the barcode area, interior margins and page limits. The kit makes Lulu covers only and runs no Lulu print checks.
- **For reference only (not encoded):** the Book Creation Guide (2026-01-21, pp. 10–11) lists Pocketbook 4.25 × 6.875, Novella 5 × 8, Digest 5.5 × 8.5, A5 5.83 × 8.27, Royal 6.14 × 9.21, US Trade 6 × 9, Comic 6.63 × 10.25, Executive 7 × 10, Crown Quarto 7.44 × 9.68, Small Square 7.5 × 7.5, A4 8.27 × 11.69, Square 8.5 × 8.5, US Letter 8.5 × 11 and landscape sizes, and recommends "a 0.50 in Safety Margin for all content" with the inside widened by page count (0.5 in to 1.25 in in all).

## eBook stores

| Store | Royalty | Source |
|---|---|---|
| Apple Books | "70% royalties on every ebook, regardless of price" | https://authors.apple.com/ |
| Kobo Writing Life | 70% at a price of at least $2.99 (US) and at least 20% below every print edition, otherwise 45% | https://cdn.kobo.com/merch-assets/writinglife/KOBO/en-US/serviceAgreement.html |
| Google Play Books | "a 70% revenue split on ebook sales in most Google Play Books supported countries" once the updated terms are accepted; 52% before that and in the countries listed | https://support.google.com/books/partner/answer/9331459 |
| Barnes & Noble Press | **Not confirmed.** The pages only render in a browser. Not used. | |

Listing requirements:

- **Apple cover:** "at least 1400 pixels along the shorter axis". Source: Apple Books Asset Guide, https://help.apple.com/itc/booksassetguide/en.lproj/itc1bda991ba.html
- **Apple description:** "It must contain at least 50 characters." Source: https://itunespartner.apple.com/books/support/12-metadata
- **Apple EPUB:** Apple supports EPUB 3.3.
- **Google cover:** "a minimum resolution of 640 pixels", up to 7,200. Source: https://support.google.com/books/partner/answer/3424254
- **Google EPUB:** accepts EPUB 3.3, EPUB 3 and EPUB 2.
- **Kobo:** covers should be 300 DPI, at most 5 MB. **Kobo publishes no minimum pixel size.**
- **ISBN:** optional at Apple ("You don't need an ISBN-13 to publish to Apple Books"). Google assigns its own identifier: "If your book doesn't have an ISBN, it's assigned a unique identifier by our system." Kobo: **not confirmed**, so the kit warns.

The cover the app makes is 1600 × 2560 px, which meets every minimum above.

## ISBN agencies
Official pages only. No fees are quoted.

| Country | Agency | Notes |
|---|---|---|
| United States | Bowker, https://www.myidentifiers.com | Listed as the US agency in the International ISBN Agency directory. Its own page refused our reader. |
| Canada | ISBN Canada at Library and Archives Canada | "ISBN Canada provides free ISBNs to eligible publishers and publications." https://www.canada.ca/en/library-archives/services/publishers/isbn.html |
| France | AFNIL, https://www.afnil.org | Serves France, Belgium and francophone Africa. "des frais de gestion de dossier s'appliquent". |
| United Kingdom and Ireland | Nielsen | https://nielseniq.com/global/en/landing-page/nielseniq-bookdata-isbn-agency |
| Haiti | **Not confirmed** | Haiti is listed in the International ISBN Agency directory, https://www.isbn-international.org/agencies, which is the link given. |
| Elsewhere | International ISBN Agency directory | |

## Legal deposit

| Country | Rule | Source |
|---|---|---|
| Haiti | 5 copies to the Bibliothèque nationale d'Haïti: "décret du 20 octobre 1984", art. 18 "Les documents doivent être déposés en cinq (5) exemplaire[s]". Other sources give 15 October 1984, so the reminder names the month only. | The BNH's own notice, published in Le Nouvelliste on 8 December 2011: https://lenouvelliste.com/article/100176/note-informative-de-la-bibliotheque-nationale-dhaiti |
| France | 1 copy to the BnF, "au plus tard le jour de leur mise en circulation". | https://www.bnf.fr/node/255 |
| United States | "two copies of each work published in the United States", "within three months of publication". | https://www.copyright.gov/mandatory/ |
| Canada | 2 copies when 100 or more are produced; 1 copy for 4 to 99. | https://www.canada.ca/en/library-archives/services/publishers/legal-deposit/physical-publications.html |
| United Kingdom | **Not confirmed.** No official page was read. | |

Every other country gets a general reminder with no figures.

## Spine reading direction

Not a print service rule: none of KDP, IngramSpark or Lulu requires a
direction. Cover Studio (ADR "Editable back cover, spine and image layers")
suggests one by the book's language and lets the author choose; read
**2026-10-28**.

- **Top to bottom** (descending): "In the United States, the United Kingdom, the Commonwealth, Scandinavia and the Netherlands, the spine text, when the book is standing upright, runs from the top to the bottom", a practice "reflected in the industry standards ANSI/NISO Z39.41 and ISO 6357". Source: https://en.wikipedia.org/wiki/Spine_(bookbinding)
- **Bottom to top** (ascending): "most of continental Europe, Latin America, and French Canada", same source. For France: the great majority of French publishers set the title reading from the bottom up (Bibliothèque municipale de Lyon, Guichet du Savoir: https://www.guichetdusavoir.org/question/voir/5717; Dan Sperber, "Pourquoi les dos des livres…", https://www.cognitionandculture.net/wp-content/uploads/Pourquoi-les-dos-des-livres-sont-ils-presque-tous-écrits-dans-le-même-sens.pdf).
- **Haitian Creole:** **not confirmed.** No authoritative source on Haitian publishing practice was found, so Kreyòl books are suggested the French convention, which Haitian publishing (and the Écrivain Prépa series) otherwise follows.
- **Resolution of pictures:** the 300 dpi KDP asks for (above) is the threshold Cover Studio warns below for image layers.