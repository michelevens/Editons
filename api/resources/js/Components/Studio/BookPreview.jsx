import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import Dialog, { CloseButton, useModalFocus } from '../Dialog';
import Icon from './Icon';
import {
    DEVICES,
    FONT_STEPS,
    bookPages,
    countPages,
    ensurePrintFonts,
    pageBox,
    pageFurniture,
    pageWarnings,
    parseLink,
    reflows,
    remember,
    remembered,
    roman,
    scopeCss,
    sectionHeadings,
    textBlock,
} from './preview';

/**
 * The book as a reader will hold it: full screen, a page at a time.
 *
 * Three devices. Phone and E-reader reflow the EPUB's own documents with
 * the EPUB's stylesheet, at a type size the reader picks; Print keeps the
 * printed book's pages at its trim size, set by PrintBook's stylesheet in
 * the faces the PDF embeds. The server sends one chapter at a time
 * (PreviewController), so a 100k-word book never arrives, or is laid out,
 * in one piece: the chapter on screen is the only one in the page, and the
 * rest are counted one by one in the background, off screen, when the
 * browser is idle.
 *
 * Pages turn with a tap on the outer thirds, a swipe, the arrow keys or the
 * buttons. "Check" flips through the book and stops on a page with a
 * problem: the ones the server found in the markup (a missing picture, a
 * cross-reference to nothing, a table too wide for a phone) and the ones
 * only a laid-out page shows (something wider or taller than the page).
 *
 * Print is the browser's pagination of PrintBook's blocks, not dompdf's
 * pages: line and page breaks can differ slightly from the PDF, and blank
 * versos are not inserted. The ADR on the preview says why.
 */
const NONE = [];

export default function BookPreview({ bookId, onClose }) {
    const root = useModalFocus(onClose);
    const [meta, setMeta] = useState(null);
    const [failure, setFailure] = useState(null);
    const [device, setDeviceState] = useState(() => {
        const saved = remembered('device', 'phone');

        return DEVICES.some((d) => d.key === saved) ? saved : 'phone';
    });
    const [font, setFontState] = useState(() => {
        const saved = remembered('font', 100);

        return FONT_STEPS.includes(saved) ? saved : 100;
    });
    const [stage, setStage] = useState(null);
    const [position, setPosition] = useState({ index: 0, page: 0 });
    const [counts, setCounts] = useState({});
    const [doc, setDoc] = useState(null);
    const [laid, setLaid] = useState(null);
    const [contents, setContents] = useState(false);
    const [check, setCheck] = useState(null);

    const stageRef = useRef(null);
    const hostRef = useRef(null);
    const measurerRef = useRef(null);
    const cache = useRef(new Map());
    // Where to land once the next chapter is laid out: a fraction of the
    // way through it (after a type-size or device change) or an anchor.
    const pending = useRef(null);
    const pointer = useRef(null);
    const swiped = useRef(false);

    const mode = reflows(device) ? 'reader' : 'print';
    const items = useMemo(
        () => (meta ? (mode === 'reader' ? meta.reader.items : meta.print.blocks) : []),
        [meta, mode],
    );
    const item = items[position.index] ?? null;
    const box = useMemo(() => (stage ? pageBox(device, stage, meta?.print) : null), [device, stage, meta]);
    const block = useMemo(() => (box ? textBlock(box) : null), [box]);
    const geometry = box ? `${device}:${font}:${box.width}x${box.height}` : null;
    const dark = typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

    // The book's contents and both stylesheets.
    useEffect(() => {
        let live = true;

        window.axios
            .get(`/books/${bookId}/preview`)
            .then((response) => live && setMeta(response.data))
            .catch((error) => live && setFailure(error.response?.data?.message ?? 'The preview could not be loaded.'));

        return () => {
            live = false;
        };
    }, [bookId]);

    // The stage's size decides the page box; a rotated phone re-lays the book.
    useLayoutEffect(() => {
        const node = stageRef.current;

        if (!node) return undefined;

        const read = () => {
            const rect = node.getBoundingClientRect();
            setStage((previous) => {
                const next = { width: Math.floor(rect.width), height: Math.floor(rect.height) };

                return previous && previous.width === next.width && previous.height === next.height ? previous : next;
            });
        };

        read();
        const observer = new ResizeObserver(read);
        observer.observe(node);

        return () => observer.disconnect();
    }, [meta]);

    // Counts belong to one geometry: another type size or screen is another book.
    useEffect(() => setCounts({}), [geometry]);

    const fetchPage = useCallback(
        (pageMode, key) => {
            const id = `${pageMode}:${key}`;

            if (!cache.current.has(id)) {
                const request = window.axios
                    .get(`/books/${bookId}/preview/page`, { params: { mode: pageMode, key } })
                    .then((response) => response.data)
                    .catch((error) => {
                        cache.current.delete(id);
                        throw error;
                    });
                cache.current.set(id, request);
            }

            return cache.current.get(id);
        },
        [bookId],
    );

    const styles = useMemo(() => {
        if (!meta || !block) return '';

        const reader = mode === 'reader';
        const ink = device === 'ereader' ? '#1f1f1f' : dark && reader ? '#e8e6e3' : '#1b1b1b';

        return `
            :host { all: initial; display: block; }
            .pv-window { width: ${block.width}px; height: ${block.height}px; overflow: hidden; position: relative; }
            ${scopeCss(reader ? meta.reader.css : meta.print.css)}
            .pv-flow {
                margin: 0 !important; padding: 0 !important; box-sizing: content-box;
                width: ${block.width}px; height: ${block.height}px;
                column-width: ${block.width}px; column-gap: ${block.gap}px; column-fill: auto;
                color: ${ink}; overflow-wrap: break-word;
                ${reader ? `font-family: Georgia, 'Iowan Old Style', 'Palatino Linotype', serif; font-size: ${(17 * font) / 100}px; hyphens: auto;` : ''}
            }
            .pv-flow section { break-before: auto !important; page-break-before: auto !important; }
            .pv-flow img, .pv-flow svg { max-width: 100%; max-height: ${block.height}px; height: auto; break-inside: avoid; }
            .pv-flow img.math { max-height: none; }
            .pv-flow a { color: inherit; }
            .pv-flow .title-page { break-after: column; }
            ${device === 'ereader' ? '.pv-flow img { filter: grayscale(1) contrast(1.05); }' : ''}
            ${dark && reader && device === 'phone' ? '.pv-flow th, .pv-flow td { border-color: #555; }' : ''}
        `;
    }, [meta, block, mode, device, font, dark]);

    // The faces print is set in, declared on the page once: a shadow root's
    // own @font-face rules are ignored by browsers.
    useEffect(() => {
        if (mode === 'print') ensurePrintFonts();
    }, [mode]);

    /** Lay a page's markup out in a host's shadow root; resolves to the flow. */
    const layOut = useCallback(
        async (host, html) => {
            const shadow = host.shadowRoot ?? host.attachShadow({ mode: 'open' });
            shadow.innerHTML = `<style>${styles}</style><div class="pv-window"><div class="pv-flow">${html}</div></div>`;
            const flow = shadow.querySelector('.pv-flow');

            if (mode === 'print' && document.fonts?.load) {
                const face = meta.print.page.font;
                await Promise.all([
                    document.fonts.load(`12pt "${face}"`),
                    document.fonts.load(`italic 12pt "${face}"`),
                ]).catch(() => null);
            }

            // Pictures change the layout as they arrive; wait a moment for them.
            await Promise.all(
                [...flow.querySelectorAll('img')].map((img) =>
                    img.complete
                        ? null
                        : new Promise((resolve) => {
                              img.addEventListener('load', resolve, { once: true });
                              img.addEventListener('error', resolve, { once: true });
                              window.setTimeout(resolve, 2500);
                          }),
                ),
            );

            return flow;
        },
        [styles, mode, meta],
    );

    // The chapter on screen: fetched, then laid out.
    const key = item?.key ?? null;

    useEffect(() => {
        if (!key) return undefined;

        let live = true;
        setDoc(null);
        setLaid(null);

        fetchPage(mode, key)
            .then((data) => live && setDoc(data))
            .catch(() => live && setDoc({ key, html: '<p>This page could not be loaded.</p>', warnings: [] }));

        return () => {
            live = false;
        };
    }, [key, mode, fetchPage]);

    useEffect(() => {
        if (!doc || !block || !hostRef.current || doc.key !== key) return undefined;

        let live = true;

        layOut(hostRef.current, doc.html).then((flow) => {
            if (!live) return;

            const pages = countPages(flow.scrollWidth, block);
            const warnings = pageWarnings(flow, block, box.scale);
            const wanted = pending.current;
            pending.current = null;

            setCounts((current) => ({ ...current, [key]: pages }));
            setLaid({ key, pages, warnings, flow, sections: mode === 'print' ? sectionHeadings(flow, block, pages) : [] });
            setPosition((current) => {
                let page = current.page === 'last' ? pages - 1 : current.page;

                if (wanted?.fraction !== undefined) page = Math.round(wanted.fraction * (pages - 1));

                if (wanted?.anchor) {
                    const target = flow.querySelector(`[id="${CSS.escape(wanted.anchor)}"]`);

                    if (target) {
                        const origin = flow.getBoundingClientRect().left;
                        page = Math.floor(((target.getBoundingClientRect().left - origin) / box.scale + 1) / (block.width + block.gap));
                    }
                }

                return { ...current, page: Math.max(0, Math.min(pages - 1, page)) };
            });
        });

        return () => {
            live = false;
        };
    }, [doc, key, block, box, layOut, mode]);

    // Slide the window onto the page.
    const ready = laid && laid.key === key;

    useLayoutEffect(() => {
        if (!ready || !block || typeof position.page !== 'number') return;

        laid.flow.style.transform = `translateX(${-position.page * (block.width + block.gap)}px)`;
    }, [ready, laid, position.page, block]);

    // The rest of the book, counted off screen one chapter at a time while
    // the browser is idle, so "12 / 240" becomes exact without a freeze.
    useEffect(() => {
        if (!ready || !measurerRef.current || !block) return undefined;

        let cancelled = false;
        const idle = () =>
            new Promise((resolve) =>
                window.requestIdleCallback ? window.requestIdleCallback(resolve, { timeout: 800 }) : window.setTimeout(resolve, 120),
            );

        (async () => {
            for (const entry of items) {
                if (cancelled) return;
                if (counts[entry.key] || entry.key === key) continue;

                await idle();
                if (cancelled) return;

                const data = await fetchPage(mode, entry.key).catch(() => null);
                if (cancelled || !data || !measurerRef.current) continue;

                const flow = await layOut(measurerRef.current, data.html);
                if (cancelled) return;

                const pages = countPages(flow.scrollWidth, block);
                setCounts((current) => (current[entry.key] ? current : { ...current, [entry.key]: pages }));

                // One chapter per pass: the effect runs again with the new count.
                return;
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [ready, items, counts, key, mode, block, fetchPage, layOut]);

    const pagesHere = ready ? laid.pages : 1;
    const numbers = useMemo(() => bookPages(items, counts), [items, counts]);
    const current = typeof position.page === 'number' ? (numbers.starts[position.index] ?? 0) + position.page + 1 : null;

    const atStart = position.index === 0 && position.page === 0;
    const atEnd = ready && position.index === items.length - 1 && position.page === laid.pages - 1;

    const next = useCallback(() => {
        if (!ready || typeof position.page !== 'number') return;

        if (position.page < laid.pages - 1) setPosition({ index: position.index, page: position.page + 1 });
        else if (position.index < items.length - 1) setPosition({ index: position.index + 1, page: 0 });
    }, [ready, laid, position, items.length]);

    const previous = useCallback(() => {
        if (!ready || typeof position.page !== 'number') return;

        if (position.page > 0) setPosition({ index: position.index, page: position.page - 1 });
        else if (position.index > 0) setPosition({ index: position.index - 1, page: 'last' });
    }, [ready, position]);

    const goToItem = useCallback(
        (index, landing = null) => {
            if (index < 0 || index >= items.length) return;

            pending.current = landing;
            setPosition({ index, page: 0 });
        },
        [items.length],
    );

    const setDevice = (value) => {
        if (value === device) return;

        // The same chapter, about as far through, in the other device.
        const fraction = pagesHere > 1 && typeof position.page === 'number' ? position.page / (pagesHere - 1) : 0;
        const nextItems = reflows(value) ? meta.reader.items : meta.print.blocks;
        const same = nextItems.findIndex((entry) => entry.key === key);

        pending.current = same >= 0 ? { fraction } : null;
        setPosition({ index: Math.max(0, same), page: 0 });
        setDeviceState(value);
        remember('device', value);
    };

    const setFont = (step) => {
        const at = FONT_STEPS.indexOf(font) + step;

        if (at < 0 || at >= FONT_STEPS.length) return;

        pending.current = { fraction: pagesHere > 1 && typeof position.page === 'number' ? position.page / (pagesHere - 1) : 0 };
        setFontState(FONT_STEPS[at]);
        remember('font', FONT_STEPS[at]);
    };

    // Keys, while no sheet is open over the preview.
    useEffect(() => {
        if (contents) return undefined;

        const onKey = (event) => {
            if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.target.closest?.('select, input, textarea')) return;

            if (['ArrowRight', 'PageDown'].includes(event.key) || (event.key === ' ' && !event.target.closest?.('button'))) {
                event.preventDefault();
                next();
            } else if (['ArrowLeft', 'PageUp'].includes(event.key)) {
                event.preventDefault();
                previous();
            } else if (event.key === 'Home') {
                event.preventDefault();
                goToItem(0);
            } else if (event.key === 'End') {
                event.preventDefault();
                pending.current = null;
                setPosition({ index: items.length - 1, page: 'last' });
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [contents, next, previous, goToItem, items.length]);

    // A link inside the book: the contents, a cross-reference, a note.
    const follow = (href) => {
        const link = parseLink(href);

        if (!link) return false;

        if (link.key && link.key !== key) {
            goToItem(items.findIndex((entry) => entry.key === link.key), link.anchor ? { anchor: link.anchor } : null);

            return true;
        }

        if (link.anchor && ready) {
            const target = laid.flow.querySelector(`[id="${CSS.escape(link.anchor)}"]`);

            if (target) {
                const origin = laid.flow.getBoundingClientRect().left;
                const page = Math.floor(((target.getBoundingClientRect().left - origin) / box.scale + 1) / (block.width + block.gap));
                setPosition((p) => ({ ...p, page: Math.max(0, Math.min(laid.pages - 1, page)) }));
            }
        }

        return true;
    };

    const onPointerDown = (event) => {
        swiped.current = false;
        pointer.current = { x: event.clientX, y: event.clientY };
    };

    const onPointerUp = (event) => {
        const start = pointer.current;
        pointer.current = null;

        if (!start) return;

        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;

        if (Math.abs(dx) > 45 && Math.abs(dx) > 1.5 * Math.abs(dy)) {
            swiped.current = true;
            dx < 0 ? next() : previous();
        }
    };

    const onStageClick = (event) => {
        if (swiped.current) {
            swiped.current = false;

            return;
        }

        const anchor = event.nativeEvent.composedPath?.().find((node) => node.tagName === 'A');

        if (anchor) {
            event.preventDefault();
            follow(anchor.getAttribute('href'));

            return;
        }

        const rect = stageRef.current.getBoundingClientRect();
        const x = event.clientX - rect.left;

        if (x < rect.width / 3) previous();
        else if (x > (rect.width * 2) / 3) next();
    };

    // The check: a page at a time, stopping where something is wrong.
    const here = (ready && typeof position.page === 'number' && laid.warnings[position.page]) || NONE;

    useEffect(() => {
        if (check?.state !== 'running' || !ready || typeof position.page !== 'number') return undefined;

        if (here.length > 0 && !check.skip) {
            setCheck({ state: 'stopped', messages: here, page: current, checked: check.checked });

            return undefined;
        }

        const timer = window.setTimeout(() => {
            if (atEnd) {
                setCheck((value) => (value?.state === 'running' ? { state: 'done', checked: value.checked + 1 } : value));

                return;
            }

            next();
            setCheck((value) => (value?.state === 'running' ? { state: 'running', checked: value.checked + 1 } : value));
        }, 260);

        return () => window.clearTimeout(timer);
    }, [check, ready, position, here, next, current, atEnd]);

    const startCheck = () => setCheck({ state: 'running', checked: 0 });
    const continueCheck = () => setCheck((value) => ({ state: 'running', checked: value?.checked ?? 0, skip: true }));

    // Running head and folio on a printed page, where PrintBook draws them
    // and with what it sets in them (pageFurniture).
    const furniture = useMemo(() => {
        if (mode !== 'print' || !ready || !item || current === null || !meta) return null;

        const restart = items.findIndex((entry) => entry.restart);
        const arabic = restart >= 0 && position.index >= restart;
        const folio = item.folio
            ? arabic
                ? String(current - numbers.starts[restart])
                : roman(current)
            : null;

        return pageFurniture(meta.print.page, item.heads, {
            verso: current % 2 === 0,
            opens: position.page === 0,
            folio,
            section: laid.sections?.[position.page] ?? null,
        });
    }, [mode, ready, item, current, meta, items, position, numbers, laid]);

    const palette =
        device === 'print'
            ? { stage: 'bg-[#4b4f54]', page: '#ffffff' }
            : device === 'ereader'
              ? { stage: 'bg-[#d9d5cc] dark:bg-[#2a2926]', page: '#f2efe6' }
              : { stage: 'bg-bg-subtle', page: dark ? '#121212' : '#ffffff' };

    const fit = box ? { width: box.width * box.scale, height: box.height * box.scale } : null;
    const estimated = numbers.estimated ? '≈' : '';

    return (
        <div
            ref={root}
            role="dialog"
            aria-modal="true"
            aria-label="Book preview"
            tabIndex={-1}
            data-a="preview:root"
            data-device={device}
            data-item={key ?? ''}
            data-page={typeof position.page === 'number' ? position.page : ''}
            data-pages={pagesHere}
            data-ready={ready ? 'true' : 'false'}
            className="fixed inset-0 z-[45] flex flex-col bg-bg-app font-ui text-text-body outline-none"
        >
            <header className="safe-top shrink-0 border-b border-border-default bg-bg-surface">
                <div className="flex items-center gap-2 px-2 pt-2 sm:px-4">
                    <CloseButton onClick={onClose} label="Close preview" className="h-10 w-10" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[14px] font-semibold text-text-primary">{meta?.book.title ?? 'Preview'}</p>
                        <p className="truncate text-[11.5px] text-text-secondary">{item?.title ?? ' '}</p>
                    </div>
                    <button
                        type="button"
                        data-a="preview:contents"
                        aria-haspopup="dialog"
                        onClick={() => setContents(true)}
                        disabled={!items.length}
                        className="tap inline-flex h-10 items-center gap-1.5 rounded-control px-2.5 text-[12.5px] font-medium text-text-body hover:bg-bg-subtle disabled:opacity-40"
                    >
                        <Icon name="list" size={17} />
                        <span className="max-sm:sr-only">Contents</span>
                    </button>
                    <button
                        type="button"
                        data-a="preview:check"
                        aria-pressed={check?.state === 'running'}
                        onClick={() => (check?.state === 'running' ? setCheck(null) : startCheck())}
                        disabled={!ready}
                        className={`tap inline-flex h-10 items-center gap-1.5 rounded-control px-2.5 text-[12.5px] font-medium hover:bg-bg-subtle disabled:opacity-40 ${
                            check?.state === 'running' ? 'text-brand-primary' : 'text-text-body'
                        }`}
                    >
                        <Icon name={check?.state === 'running' ? 'stop' : 'play'} size={16} />
                        <span>{check?.state === 'running' ? 'Stop' : 'Check'}</span>
                    </button>
                </div>

                <div className="flex flex-wrap items-center gap-2 px-2 pb-2 pt-1.5 sm:px-4">
                    <div role="radiogroup" aria-label="Device" className="grid grid-cols-3 gap-0.5 rounded-control bg-bg-subtle p-0.5">
                        {DEVICES.map((option) => (
                            <button
                                key={option.key}
                                type="button"
                                role="radio"
                                aria-checked={device === option.key}
                                data-a={`preview:device:${option.key}`}
                                onClick={() => setDevice(option.key)}
                                disabled={!meta}
                                className={`flex h-9 items-center justify-center gap-1.5 rounded-[5px] px-2 text-[12.5px] transition sm:px-2.5 ${
                                    device === option.key
                                        ? 'bg-bg-surface font-semibold text-text-primary shadow-floatingButton'
                                        : 'text-text-secondary hover:text-text-primary'
                                }`}
                            >
                                <Icon name={option.icon} size={14} className="max-sm:hidden" />
                                {option.label}
                            </button>
                        ))}
                    </div>

                    {reflows(device) && (
                        <div role="group" aria-label="Type size" className="ml-auto flex items-center gap-0.5">
                            <button
                                type="button"
                                data-a="preview:font-down"
                                aria-label="Smaller type"
                                onClick={() => setFont(-1)}
                                disabled={font === FONT_STEPS[0]}
                                className="tap grid h-9 w-9 place-items-center rounded-control text-[13px] font-semibold text-text-body hover:bg-bg-subtle disabled:opacity-40"
                            >
                                A−
                            </button>
                            <span data-a="preview:font-size" aria-live="polite" className="w-11 text-center text-[12px] tabular-nums text-text-secondary">
                                {font}%
                            </span>
                            <button
                                type="button"
                                data-a="preview:font-up"
                                aria-label="Larger type"
                                onClick={() => setFont(1)}
                                disabled={font === FONT_STEPS[FONT_STEPS.length - 1]}
                                className="tap grid h-9 w-9 place-items-center rounded-control text-[16px] font-semibold text-text-body hover:bg-bg-subtle disabled:opacity-40"
                            >
                                A+
                            </button>
                        </div>
                    )}
                    {device === 'print' && meta && (
                        <span className="ml-auto text-[11.5px] text-text-secondary">
                            {meta.layout.trim} · {meta.layout.margins.toLowerCase()} margins
                        </span>
                    )}
                </div>
            </header>

            <div
                ref={stageRef}
                data-a="preview:stage"
                onPointerDown={onPointerDown}
                onPointerUp={onPointerUp}
                onPointerCancel={() => (pointer.current = null)}
                onClick={onStageClick}
                style={{ touchAction: 'pan-y pinch-zoom' }}
                className={`relative min-h-0 flex-1 select-none overflow-hidden ${palette.stage} flex items-center justify-center`}
            >
                {failure ? (
                    <p role="alert" className="px-6 text-center text-[13px] text-status-danger">{failure}</p>
                ) : meta && items.length === 0 ? (
                    <p className="px-6 text-center text-[13px] text-text-secondary">Write a chapter to preview the book.</p>
                ) : !box ? null : (
                    <div style={fit} className="relative shrink-0">
                        <div
                            data-a="preview:page"
                            style={{
                                width: box.width,
                                height: box.height,
                                transform: box.scale === 1 ? undefined : `scale(${box.scale})`,
                                transformOrigin: '0 0',
                                background: palette.page,
                            }}
                            className={`relative overflow-hidden ${
                                device === 'print' ? 'shadow-[0_6px_28px_rgba(0,0,0,0.45)]' : 'sm:rounded-[14px] sm:shadow-popover'
                            }`}
                        >
                            <div
                                ref={hostRef}
                                style={{ position: 'absolute', top: box.pad[0], left: box.pad[3], width: block.width, height: block.height }}
                            />
                            {furniture?.map((entry) => (
                                <p key={entry.key} aria-hidden="true" data-a={`preview:${entry.key}`} style={entry.style}>
                                    {entry.parts.map((part, index) => (
                                        <span key={index} style={part.style}>{part.text}</span>
                                    ))}
                                </p>
                            ))}
                            {!ready && (
                                <p className="absolute inset-0 grid place-items-center text-[12px] text-[#777]">Laying out…</p>
                            )}
                        </div>
                    </div>
                )}

                {/* Off screen, where the rest of the book is counted. */}
                {block && (
                    <div
                        ref={measurerRef}
                        aria-hidden="true"
                        style={{ position: 'absolute', left: -100000, top: 0, width: block.width, height: block.height, visibility: 'hidden', pointerEvents: 'none' }}
                    />
                )}

                {here.length > 0 && check?.state !== 'stopped' && (
                    <span
                        data-a="preview:page-warning"
                        title={here.join(' ')}
                        className="absolute right-3 top-3 rounded-badge bg-status-warning-bg px-2 py-1 text-[11px] font-semibold text-status-warning shadow-floatingButton"
                    >
                        {here.length} issue{here.length === 1 ? '' : 's'} on this page
                    </span>
                )}
            </div>

            {check && check.state !== 'running' && (
                <div
                    role="status"
                    data-a="preview:check-result"
                    className="shrink-0 border-t border-border-default bg-bg-surface px-3 py-2.5 sm:px-4"
                >
                    {check.state === 'stopped' ? (
                        <>
                            <p className="text-[12.5px] font-semibold text-status-warning">Page {check.page}:</p>
                            <ul className="mt-0.5 list-disc pl-4 text-[12.5px] text-text-body">
                                {check.messages.map((message) => (
                                    <li key={message}>{message}</li>
                                ))}
                            </ul>
                        </>
                    ) : (
                        <p className="text-[12.5px] text-text-body">
                            Checked {check.checked} page{check.checked === 1 ? '' : 's'} to the end: no layout problems found.
                        </p>
                    )}
                    <div className="mt-2 flex gap-2">
                        {check.state === 'stopped' && (
                            <button
                                type="button"
                                data-a="preview:check-continue"
                                onClick={continueCheck}
                                className="tap rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white"
                            >
                                Continue check
                            </button>
                        )}
                        <button
                            type="button"
                            data-a="preview:check-dismiss"
                            onClick={() => setCheck(null)}
                            className="tap rounded-control border border-border-input px-3 text-[12.5px] font-medium text-text-body"
                        >
                            {check.state === 'stopped' ? 'Stop here' : 'OK'}
                        </button>
                    </div>
                </div>
            )}

            <footer className="safe-bottom flex shrink-0 items-center gap-2 border-t border-border-default bg-bg-surface px-2 py-1.5 sm:px-4">
                <button
                    type="button"
                    data-a="preview:prev"
                    aria-label="Previous page"
                    onClick={previous}
                    disabled={!ready || atStart}
                    className="tap grid h-10 w-10 place-items-center rounded-control text-text-body hover:bg-bg-subtle disabled:opacity-40"
                >
                    <Icon name="left" size={18} />
                </button>
                <p className="min-w-0 flex-1 text-center">
                    <span data-a="preview:indicator" aria-live="polite" className="block text-[13px] font-semibold tabular-nums text-text-primary">
                        {current === null ? '–' : `${current} / ${estimated}${numbers.total}`}
                    </span>
                    <span className="block truncate text-[11px] text-text-secondary">
                        Page {typeof position.page === 'number' ? position.page + 1 : 1} of {pagesHere} in this {item?.kind === 'part' ? 'part' : 'section'}
                    </span>
                </p>
                <button
                    type="button"
                    data-a="preview:next"
                    aria-label="Next page"
                    onClick={next}
                    disabled={!ready || atEnd}
                    className="tap grid h-10 w-10 place-items-center rounded-control text-text-body hover:bg-bg-subtle disabled:opacity-40"
                >
                    <Icon name="right" size={18} />
                </button>
            </footer>

            {contents && (
                <Dialog
                    label="Contents"
                    onClose={() => setContents(false)}
                    data-a="sheet:preview-contents"
                    className="flex max-h-[80dvh] w-[420px] flex-col rounded-panel bg-bg-surface shadow-popover"
                >
                    <div className="flex shrink-0 items-center justify-between border-b border-border-divider px-4 py-2.5">
                        <p className="text-[14px] font-semibold text-text-primary">Contents</p>
                        <CloseButton onClick={() => setContents(false)} label="Close contents" />
                    </div>
                    <ol className="min-h-0 overflow-y-auto py-1">
                        {items.map((entry, index) => (
                            <li key={entry.key}>
                                <button
                                    type="button"
                                    data-a={`preview:jump:${entry.key}`}
                                    aria-current={index === position.index ? 'true' : undefined}
                                    onClick={() => {
                                        setContents(false);
                                        goToItem(index);
                                    }}
                                    style={{ paddingLeft: 16 + (entry.depth ?? 0) * 14 }}
                                    className={`flex min-h-[44px] w-full items-center gap-3 pr-4 text-left text-[13.5px] transition hover:bg-bg-subtle ${
                                        index === position.index ? 'font-semibold text-brand-primary' : 'text-text-body'
                                    }`}
                                >
                                    <span className="min-w-0 flex-1 truncate">
                                        {entry.label ? `${entry.label} · ` : ''}
                                        {entry.title}
                                    </span>
                                    <span className="shrink-0 text-[12px] tabular-nums text-text-secondary">
                                        {counts[entry.key] || !numbers.estimated ? '' : '≈'}
                                        {(numbers.starts[index] ?? 0) + 1}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </Dialog>
            )}
        </div>
    );
}
