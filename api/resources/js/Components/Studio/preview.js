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
