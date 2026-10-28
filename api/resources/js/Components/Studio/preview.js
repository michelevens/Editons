/**
 * The arithmetic behind the full-screen preview (BookPreview.jsx), kept
 * apart from the component so each piece is small enough to reason about.
 *
 * Pages are CSS columns: a chapter is laid out in a box one page high, the
 * browser breaks it into page-wide columns, and turning a page slides the
 * next column into a window the size of one. The server sends the markup
 * and stylesheet the EPUB and the PDF are made from; nothing here decides
 * how a book is set, only where its pages fall on this screen.
 */

export const DEVICES = [
    { key: 'phone', label: 'Phone', icon: 'phone' },
    { key: 'ereader', label: 'E-reader', icon: 'tablet' },
    { key: 'print', label: 'Print', icon: 'doc' },
];

/** Type sizes a reader can step through, in percent of the device's own. */
export const FONT_STEPS = [80, 90, 100, 115, 130, 150, 175];

/** CSS pixels per inch: the browser's, which is what print sizes resolve to. */
export const PX_PER_INCH = 96;

/** Whether a device reflows text (phone, e-reader) or keeps fixed pages. */
export const reflows = (device) => device !== 'print';

/**
 * The server's stylesheet, made to apply inside the preview's own shadow
 * root: what it says about `body` is said about the flow, and @page rules,
 * which only a paginating renderer reads, are dropped.
 */
export function scopeCss(css) {
    return String(css ?? '')
        .replace(/@page\s*\{[^}]*\}/g, '')
        .replace(/(^|[}\s,;])body(?=\s*[{,])/g, '$1.pv-flow');
}

/**
 * The page box for a device in a stage of the given size, in CSS pixels:
 * `width` and `height` of the page, `pad` around the text block (top,
 * right, bottom, left) and `scale` for a printed page drawn smaller than
 * life to fit the screen.
 */
export function pageBox(device, stage, print) {
    if (device === 'print') {
        const page = print?.page ?? { width: 6, height: 9, top: 0.9, bottom: 0.95, inside: 0.78, outside: 0.78 };
        const width = page.width * PX_PER_INCH;
        const height = page.height * PX_PER_INCH;
        const scale = Math.max(0.2, Math.min((stage.width - 24) / width, (stage.height - 24) / height, 1.6));

        return {
            width,
            height,
            scale,
            pad: [page.top, page.outside, page.bottom, page.inside].map((inches) => inches * PX_PER_INCH),
        };
    }

    // A phone fills a phone; on a wider screen it is drawn at a phone's
    // size, and an e-reader at a 6-inch reader's, so a line holds as many
    // words as it would on the device.
    const [maxWidth, maxHeight, pad] = device === 'phone' ? [400, 860, [26, 22, 26, 22]] : [580, 780, [34, 34, 34, 34]];

    return {
        width: Math.floor(Math.min(stage.width, maxWidth)),
        height: Math.floor(Math.min(stage.height, maxHeight)),
        scale: 1,
        pad,
    };
}

/** The text block inside a page box: where the columns go. */
export function textBlock(box) {
    const [top, right, bottom, left] = box.pad;

    return {
        width: Math.max(80, Math.floor(box.width - left - right)),
        height: Math.max(80, Math.floor(box.height - top - bottom)),
        // Columns sit a full margin apart, so one never shows at the edge
        // of the window onto the next.
        gap: Math.ceil(left + right),
    };
}

/** How many pages a laid-out flow takes. */
export function countPages(scrollWidth, block) {
    return Math.max(1, Math.round((scrollWidth + block.gap) / (block.width + block.gap)));
}

/**
 * The book's page numbers from what has been measured so far.
 *
 * Every chapter that has been laid out counts exactly; one not yet laid out
 * is estimated from its words at the rate of those that have been, so the
 * indicator can say "12 / ≈240" before the whole book has been measured,
 * and the number firms up as the background count reaches each chapter.
 */
export function bookPages(items, counts) {
    let words = 0;
    let pages = 0;

    for (const item of items) {
        const n = counts[item.key];

        if (n && item.words > 0) {
            words += item.words;
            pages += n;
        }
    }

    const rate = pages > 0 ? words / pages : 260;
    let estimated = false;
    const starts = [];
    let total = 0;

    for (const item of items) {
        starts.push(total);
        const n = counts[item.key];

        if (n) {
            total += n;
        } else {
            estimated = true;
            total += Math.max(1, Math.ceil((item.words || 0) / rate));
        }
    }

    return { starts, total, estimated };
}

/**
 * Pages of the flow that hold an element tagged by the server
 * (data-pv-warn) or found here to overflow its page, with what is wrong.
 *
 * The server can only see markup. That a table is wider than this page or
 * a picture taller than it is known once the browser has laid the page
 * out, so that half is checked here, against the text block.
 */
export function pageWarnings(flow, block, scale = 1) {
    const found = {};
    const origin = flow.getBoundingClientRect();
    const pageOf = (el) => {
        const rect = el.getBoundingClientRect();

        return Math.max(0, Math.floor(((rect.left - origin.left) / scale + 1) / (block.width + block.gap)));
    };
    const add = (el, message) => {
        const page = pageOf(el);
        found[page] = found[page] ?? [];

        if (!found[page].includes(message)) found[page].push(message);
    };

    for (const el of flow.querySelectorAll('[data-pv-warn]')) {
        add(el, WARNINGS[el.getAttribute('data-pv-warn')] ?? 'Something on this page needs a look.');
    }

    for (const el of flow.querySelectorAll('img, table, pre, svg, figure, .chart')) {
        // An element inside one already checked is that one's problem.
        if (el.parentElement?.closest('table, pre, figure, .chart')) continue;

        const rect = el.getBoundingClientRect();
        const width = Math.max(rect.width / scale, el.scrollWidth || 0);

        if (width > block.width + 2) {
            add(el, el.tagName === 'TABLE' ? 'A table runs past the edge of the page.' : 'Something on this page is wider than the page.');
        } else if (rect.height / scale > block.height + 2) {
            add(el, 'Something on this page is taller than the page.');
        }

        if (el.tagName === 'IMG' && el.complete && el.naturalWidth === 0 && !el.hasAttribute('data-pv-warn')) {
            add(el, WARNINGS['missing-image']);
        }
    }

    return found;
}

const WARNINGS = {
    'missing-image': 'A picture on this page is missing.',
    'missing-reference': 'A cross-reference on this page points at nothing.',
    'wide-table': 'A table on this page has more columns than a phone screen holds.',
};

/** "preview-s12#fig-1" → { key: 's12', anchor: 'fig-1' }; "#fn-1" → { anchor }. */
export function parseLink(href) {
    if (!href) return null;

    const inBook = /^preview-([a-z0-9-]+)(?:#(.+))?$/.exec(href);

    if (inBook) return { key: inBook[1], anchor: inBook[2] ?? null };
    if (href.startsWith('#')) return { key: null, anchor: href.slice(1) };

    return null;
}

/** Roman folios for the front matter, as print numbers them. */
export function roman(n) {
    const table = [[1000, 'm'], [900, 'cm'], [500, 'd'], [400, 'cd'], [100, 'c'], [90, 'xc'], [50, 'l'], [40, 'xl'], [10, 'x'], [9, 'ix'], [5, 'v'], [4, 'iv'], [1, 'i']];
    let out = '';

    for (const [value, letters] of table) {
        while (n >= value) {
            out += letters;
            n -= value;
        }
    }

    return out;
}

/** Read and write a per-browser preference; storage can be missing. */
export function remembered(key, fallback) {
    try {
        const value = window.localStorage.getItem(`preview:${key}`);

        return value === null ? fallback : JSON.parse(value);
    } catch {
        return fallback;
    }
}

export function remember(key, value) {
    try {
        window.localStorage.setItem(`preview:${key}`, JSON.stringify(value));
    } catch {
        // Private windows and blocked storage: the choice lasts the visit.
    }
}

/**
 * The faces the printed book is set in, declared on the page once (a
 * shadow root's own @font-face rules are ignored by browsers). Served by
 * PreviewController from resources/fonts, the files the PDF embeds.
 */
export function ensurePrintFonts() {
    if (typeof document === 'undefined' || document.getElementById('pv-fonts')) return;

    const face = (family, file, weight, style) =>
        `@font-face { font-family: '${family}'; src: url('/preview/fonts/${file}') format('truetype'); font-weight: ${weight}; font-style: ${style}; font-display: swap; }`;
    const style = document.createElement('style');
    style.id = 'pv-fonts';
    // Every interior face (App\Publishing\InteriorFonts): regular, italic,
    // bold and bold italic. A browser fetches only the ones a page uses.
    style.textContent = PRINT_FACES.flatMap(([family, [regular, italic, bold, boldItalic], weight]) => [
        face(family, regular, 400, 'normal'),
        face(family, italic, 400, 'italic'),
        face(family, bold, `${weight} 700`, 'normal'),
        face(family, boldItalic, `${weight} 700`, 'italic'),
    ]).join('\n');
    document.head.appendChild(style);
}

/** The interior faces and their files, as InteriorFonts lists them: family, files, bold weight. */
export const PRINT_FACES = [
    ['EB Garamond', ['EBGaramond-Regular.ttf', 'EBGaramond-Italic.ttf', 'EBGaramond-SemiBold.ttf', 'EBGaramond-SemiBoldItalic.ttf'], 600],
    ['Lora', ['Lora-Regular.ttf', 'Lora-Italic.ttf', 'Lora-Bold.ttf', 'Lora-BoldItalic.ttf'], 700],
    ['Crimson Pro', ['CrimsonPro-Regular.ttf', 'CrimsonPro-Italic.ttf', 'CrimsonPro-SemiBold.ttf', 'CrimsonPro-SemiBoldItalic.ttf'], 600],
    ['Literata', ['Literata-Regular.ttf', 'Literata-Italic.ttf', 'Literata-Bold.ttf', 'Literata-BoldItalic.ttf'], 700],
    ['Spectral', ['Spectral-Regular.ttf', 'Spectral-Italic.ttf', 'Spectral-SemiBold.ttf', 'Spectral-SemiBoldItalic.ttf'], 600],
    ['Alegreya', ['Alegreya-Regular.ttf', 'Alegreya-Italic.ttf', 'Alegreya-Bold.ttf', 'Alegreya-BoldItalic.ttf'], 700],
    ['Source Sans 3', ['SourceSans3-Regular.ttf', 'SourceSans3-It.ttf', 'SourceSans3-Semibold.ttf', 'SourceSans3-SemiboldIt.ttf'], 600],
];

/**
 * Where a printed page's running head and folio go, as PrintBook::decorate()
 * draws them: the server decides what each head says and how it is set
 * (InteriorDesign::head, sent as `heads`) and where the folio goes (`page`);
 * this only turns that into positioned boxes, in CSS pixels of a page drawn
 * at 96 px to the inch. `section` is the heading the page is in, for a head
 * that shows the section.
 *
 * @returns {Array<{key: string, style: object, parts: Array<{text: string, style: object}>}>}
 */
export function pageFurniture(page, heads, { verso, opens, folio, section = null }) {
    if (!page) return [];

    // The text block's edges on this side of the spread: a verso has its
    // outside margin at the left, a recto at the right (equal unless the
    // book's margins are mirrored).
    const left = (verso ? page.outside : page.inside) * PX_PER_INCH;
    const right = (verso ? page.inside : page.outside) * PX_PER_INCH;
    const showHead = page.headers && (!opens || page.openers);
    let head = showHead ? (heads?.[verso ? 'verso' : 'recto'] ?? null) : null;

    if (head && head.source === 'section' && section) {
        head = { ...head, text: head.upper ? section.toUpperCase() : section };
    }

    let place = page.folio ?? 'bottom-center';

    // A folio at the head drops to the foot on an opener, which has no head.
    if (opens && (place === 'top-outside' || place === 'with-head') && !head) place = 'bottom-center';

    const beside = place === 'with-head' || (place === 'top-outside' && page.align === 'outside');
    const face = `'${page.font}', serif`;
    const grey = '#545454';
    const line = (top) => ({
        position: 'absolute', top, left, right, lineHeight: 1.3, fontFamily: face, color: grey,
        whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
    });
    const headTop = (page.head_at ?? 0.5) * PX_PER_INCH;
    const footTop = (page.foot_at ?? page.height - 0.62) * PX_PER_INCH;
    const folioPart = folio ? { text: folio, style: { fontSize: `${page.folio_size ?? 8.5}pt` } } : null;
    const headPart = head
        ? { text: head.text, style: { fontSize: `${head.size}pt`, letterSpacing: `${head.spacing}pt`, fontStyle: head.italic ? 'italic' : 'normal', overflow: 'hidden', textOverflow: 'ellipsis' } }
        : null;
    const out = [];

    if (beside && (place === 'with-head' || place === 'top-outside')) {
        const parts = [folioPart, headPart].filter(Boolean);
        out.push({
            key: 'head',
            style: { ...line(headTop), display: 'flex', gap: `${1.4 * (page.folio_size ?? 8.5)}pt`, justifyContent: verso ? 'flex-start' : 'flex-end' },
            parts: verso ? parts : parts.reverse(),
        });

        return out;
    }

    if (headPart) {
        out.push({
            key: 'head',
            style: { ...line(headTop), textAlign: page.align === 'outside' ? (verso ? 'left' : 'right') : 'center' },
            parts: [headPart],
        });
    }

    if (folioPart) {
        const outside = verso ? 'left' : 'right';
        out.push({
            key: 'folio',
            style: place === 'bottom-center'
                ? { ...line(footTop), textAlign: 'center' }
                : { ...line(place === 'bottom-outside' ? footTop : headTop), textAlign: outside },
            parts: [folioPart],
        });
    }

    return out;
}

/**
 * The last section heading (h1 or h2 of the text, not the opener's title)
 * that begins on or before each page of a laid-out flow, for running heads
 * that show the section.
 */
export function sectionHeadings(flow, block, pages) {
    const found = [...flow.querySelectorAll('.text h1[id], .text h2[id]')].map((el) => ({
        page: Math.floor((el.offsetLeft + 1) / (block.width + block.gap)),
        text: el.textContent.replace(/\s+/g, ' ').trim(),
    }));
    const out = [];
    let current = null;

    for (let page = 0; page < pages; page++) {
        for (const heading of found) {
            if (heading.page === page && heading.text) current = heading.text;
        }
        out.push(current);
    }

    return out;
}
