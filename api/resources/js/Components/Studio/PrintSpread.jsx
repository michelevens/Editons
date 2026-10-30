import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useI18n } from '../../i18n';
import { PX_PER_INCH, countPages, ensurePrintFonts, pageFurniture, roman, scopeCss, sectionHeadings, textBlock } from './preview';

/**
 * A chapter's opening pages as the printed book sets them, for Design &
 * Layout: the opener on its recto, then the spread after it.
 *
 * Nothing here decides how the book is set. The server sends the chapter
 * as PrintBook's HTML, PrintBook's stylesheet, the page geometry and the
 * running heads InteriorDesign sets (PrintBook::designPreview); the page is
 * drawn at its trim in CSS inches in the faces the PDF embeds, the way the
 * full-screen preview's Print mode draws it (ADR-0025), with the heads and
 * folios placed by pageFurniture. Line breaks are the browser's, so they can
 * differ slightly from dompdf's; everything a setting changes (faces,
 * margins, indents, openers, heads, folios, boxes) is the PDF's own.
 *
 * A new render is laid out out of sight and only replaces the one on screen
 * once its fonts and pictures are in, so a setting never makes the page
 * blink or jump (the lesson of Cover Studio, ADR-0038).
 */
export default function PrintSpread({ preview }) {
    const [slots, setSlots] = useState({ a: preview, b: null, active: 'a', ready: { a: false, b: false } });
    const container = useRef(null);
    const [width, setWidth] = useState(0);

    // A new preview goes into the slot not on screen.
    useEffect(() => {
        setSlots((current) => {
            const shown = current[current.active];

            if (shown === preview) return current;

            if (!current.ready[current.active]) {
                // Nothing laid out yet: draw the newest straight away.
                return { ...current, [current.active]: preview };
            }

            const spare = current.active === 'a' ? 'b' : 'a';

            return { ...current, [spare]: preview, ready: { ...current.ready, [spare]: false } };
        });
    }, [preview]);

    useLayoutEffect(() => {
        const node = container.current;

        if (!node) return undefined;

        const read = () => setWidth(Math.floor(node.getBoundingClientRect().width));
        read();
        const observer = new ResizeObserver(read);
        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    const onReady = (slot, data) =>
        setSlots((current) => {
            if (current[slot] !== data) return current;

            return { ...current, active: slot, ready: { ...current.ready, [slot]: true } };
        });

    const busy = slots[slots.active] !== preview;

    return (
        <div ref={container} className="relative" data-a="layout:print-preview" data-busy={busy ? 'true' : 'false'}>
            {['a', 'b'].map((slot) =>
                slots[slot] && width > 0 ? (
                    <div
                        key={slot}
                        aria-hidden={slot !== slots.active}
                        data-slot={slot === slots.active ? 'shown' : 'pending'}
                        // Opacity, not visibility: the page's shadow root resets
                        // inherited properties (all: initial), visibility among them.
                        style={slot === slots.active ? undefined : { position: 'absolute', inset: 0, opacity: 0, pointerEvents: 'none', overflow: 'hidden' }}
                    >
                        <Pages data={slots[slot]} width={width} onReady={() => onReady(slot, slots[slot])} />
                    </div>
                ) : null,
            )}
        </div>
    );
}

/** The opener, then the spread after it, scaled to fit the panel. */
function Pages({ data, width, onReady }) {
    const { t } = useI18n();
    const page = data.page;
    const pageWidth = page.width * PX_PER_INCH;
    const pageHeight = page.height * PX_PER_INCH;
    const block = textBlock({ width: pageWidth, height: pageHeight, pad: [page.top, page.outside, page.bottom, page.inside].map((i) => i * PX_PER_INCH) });
    const wide = width >= 640;
    const gap = 16;
    // Three pages in a row when there is room; otherwise the opener, then
    // the spread, each the panel's width.
    const scale = wide ? Math.min(1, (width - 2 * gap) / (3 * pageWidth)) : Math.min(1, width / (2 * pageWidth));
    const openerScale = wide ? scale : Math.min(1, width / pageWidth) * 0.8;

    const [laid, setLaid] = useState(null);
    const hosts = [useRef(null), useRef(null), useRef(null)];
    const reported = useRef(false);

    useEffect(() => {
        let live = true;
        reported.current = false;
        setLaid(null);
        ensurePrintFonts();

        const css = `
            :host { all: initial; display: block; }
            .pv-window { width: ${block.width}px; height: ${block.height}px; overflow: hidden; position: relative; }
            ${scopeCss(data.css)}
            .pv-flow {
                margin: 0 !important; padding: 0 !important; box-sizing: content-box; position: relative;
                width: ${block.width}px; height: ${block.height}px;
                column-width: ${block.width}px; column-gap: ${block.gap}px; column-fill: auto;
                color: #1b1b1b; overflow-wrap: break-word;
            }
            .pv-flow section { break-before: auto !important; page-break-before: auto !important; }
            .pv-flow img, .pv-flow svg { max-width: 100%; max-height: ${block.height}px; height: auto; break-inside: avoid; }
            .pv-flow img.math { max-height: none; }
        `;

        const flows = hosts.map((ref, index) => {
            const host = ref.current;

            if (!host) return null;

            const shadow = host.shadowRoot ?? host.attachShadow({ mode: 'open' });
            shadow.innerHTML = `<style>${css}</style><div class="pv-window"><div class="pv-flow">${data.html}</div></div>`;
            const flow = shadow.querySelector('.pv-flow');
            flow.style.transform = `translateX(${-index * (block.width + block.gap)}px)`;

            return flow;
        });

        (async () => {
            if (document.fonts?.load) {
                // The body face, and the heading face when the book has one.
                const faces = [page.font, page.heading_font].filter(Boolean);
                await Promise.all(faces.flatMap((family) => [
                    document.fonts.load(`12pt "${family}"`),
                    document.fonts.load(`italic 12pt "${family}"`),
                    document.fonts.load(`bold 12pt "${family}"`),
                ])).catch(() => null);
            }

            const images = flows.filter(Boolean).flatMap((flow) => [...flow.querySelectorAll('img')]);
            await Promise.all(
                images.map((img) =>
                    img.complete
                        ? null
                        : new Promise((resolve) => {
                              img.addEventListener('load', resolve, { once: true });
                              img.addEventListener('error', resolve, { once: true });
                              window.setTimeout(resolve, 2500);
                          }),
                ),
            );

            if (!live || !flows[0]) return;

            const pages = countPages(flows[0].scrollWidth, block);
            setLaid({ pages, sections: sectionHeadings(flows[0], block, Math.max(3, pages)) });
        })();

        return () => {
            live = false;
        };
        // The data object is replaced whenever anything changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, block.width, block.height]);

    useEffect(() => {
        if (laid && !reported.current) {
            reported.current = true;
            onReady();
        }
    }, [laid, onReady]);

    // The opener opens a recto; the spread after it is verso, recto.
    const folios = data.roman ? [roman(1), roman(2), roman(3)] : ['1', '2', '3'];
    const sheets = [
        { index: 0, verso: false, label: t('preview.opening_page') },
        { index: 1, verso: true, label: t('preview.verso') },
        { index: 2, verso: false, label: t('preview.recto') },
    ];

    const sheet = ({ index, verso, label }, sheetScale) => {
        const items = pageFurniture(page, data.heads, {
            verso,
            opens: index === 0,
            folio: data.folio ? folios[index] : null,
            section: laid?.sections?.[index] ?? null,
        });
        const empty = laid && index >= laid.pages;

        return (
            <figure key={index} className="m-0 shrink-0" data-a={`layout:page-${index}`} data-empty={empty ? 'true' : 'false'}>
                <div style={{ width: pageWidth * sheetScale, height: pageHeight * sheetScale }} className="relative overflow-hidden bg-white shadow-[0_1px_8px_rgba(0,0,0,0.18)]">
                    <div style={{ width: pageWidth, height: pageHeight, transform: `scale(${sheetScale})`, transformOrigin: '0 0', position: 'relative' }}>
                        <div
                            ref={hosts[index]}
                            data-a={`layout:page-host-${index}`}
                            style={{
                                position: 'absolute',
                                top: page.top * PX_PER_INCH,
                                // A verso's inside margin is on its right; with
                                // symmetric margins the two are the same.
                                left: (verso ? page.outside : page.inside) * PX_PER_INCH,
                                width: block.width,
                                height: block.height,
                                visibility: empty ? 'hidden' : 'visible',
                            }}
                        />
                        {!empty &&
                            items.map((entry) => (
                                <p key={entry.key} aria-hidden="true" data-a={`layout:${entry.key}-${index}`} style={{ margin: 0, ...entry.style }}>
                                    {entry.parts.map((part, at) => (
                                        <span key={at} style={part.style}>{part.text}</span>
                                    ))}
                                </p>
                            ))}
                    </div>
                </div>
                <figcaption className="mt-1 text-center text-[10.5px] text-text-secondary">{label}</figcaption>
            </figure>
        );
    };

    return (
        <div className={wide ? 'flex items-start justify-center gap-4' : 'flex flex-col items-center gap-3'}>
            {sheet(sheets[0], openerScale)}
            <div className="flex items-start" style={{ gap: 0 }}>
                {sheet(sheets[1], scale)}
                {sheet(sheets[2], scale)}
            </div>
        </div>
    );
}
