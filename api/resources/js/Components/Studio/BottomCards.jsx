import { useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * The bottom row: Research Library, Design Preview, Cover Studio, Export.
 *
 * HANDOFF.md components 7-10. Research and Export are wired to real
 * engines. Design and Cover still need a typesetting and an image pipeline,
 * so they say so rather than showing a sample spread that misrepresents what
 * the app does.
 *
 * Export said the same thing until it stopped being true: EPUB, DOCX,
 * Markdown and print-ready HTML have all generated for some time, and the
 * card was still telling authors the renderers did not exist. A placeholder
 * that outlives its feature is worse than no card, because it talks an
 * author out of trying something that works.
 *
 * Width ratios come from tokens.layout.bottomCards.
 */

export function BottomRow({ bookId, sources, onOpenLibrary, onAddSource, onOpenExport }) {
    return (
        <div className="grid h-full min-h-0 gap-[10px] [grid-template-columns:repeat(2,minmax(0,1fr))] lg:[grid-template-columns:217fr_371fr_188fr_210fr]">
            <ResearchCard
                sources={sources}
                onOpen={onOpenLibrary}
                onAdd={onAddSource}
            />
            <PlaceholderCard
                title="Book Design Preview"
                what="A two-page spread typeset from your real chapter text, at the trim size you choose."
                needs="a typesetting pipeline"
            />
            <PlaceholderCard
                title="Cover Studio"
                what="A live cover editor with colour schemes and artwork upload."
                needs="image storage"
            />
            <ExportCard onOpen={onOpenExport} />
        </div>
    );
}

/**
 * Export, which is real.
 *
 * Deliberately does not generate from here: a click that silently spends
 * nothing but takes a few seconds, with no format chosen, is worse than a
 * door into the panel where the formats and their costs are visible.
 */
function ExportCard({ onOpen }) {
    return (
        <Card
            title="Export & Publish"
            action={
                <button
                    data-a="bottom:export"
                    onClick={onOpen}
                    className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                >
                    Open
                </button>
            }
        >
            <div className="p-3">
                <p className="text-[11.5px] leading-relaxed text-text-secondary">
                    EPUB 3, Word, Markdown and print-ready HTML, generated from the
                    manuscript as it stands.
                </p>

                <ul className="mt-2.5 space-y-1">
                    {[
                        ['EPUB 3', 'Kindle, Apple Books, Kobo'],
                        ['Word', 'editors and co-authors'],
                        ['Markdown', 'plain text, structure kept'],
                        ['Print-ready HTML', 'print to PDF at your trim size'],
                    ].map(([name, what]) => (
                        <li key={name} className="flex items-baseline gap-1.5">
                            <Icon
                                name="check"
                                size={12}
                                strokeWidth={2.6}
                                className="shrink-0 translate-y-px text-status-success"
                            />
                            <span className="text-[11px] text-text-primary">{name}</span>
                            <span className="truncate text-[10.5px] text-text-secondary">
                                {what}
                            </span>
                        </li>
                    ))}
                </ul>

                <p className="mt-2.5 text-[10.5px] leading-relaxed text-text-secondary">
                    A real PDF renderer is still to come — the print-ready file is HTML
                    paginated at your trim size, which a browser prints to PDF.
                </p>
            </div>
        </Card>
    );
}

function Card({ title, action, children }) {
    return (
        <section className="flex min-w-0 flex-col overflow-hidden rounded-panel border border-border-default bg-bg-surface">
            <header className="flex h-[38px] shrink-0 items-center justify-between border-b border-border-divider px-3">
                <h3 className="truncate text-[13px] font-semibold text-text-primary">
                    {title}
                </h3>
                {action}
            </header>
            <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
        </section>
    );
}

const FILTERS = [
    ['all', 'All'],
    ['pdf', 'PDFs'],
    ['web', 'Web'],
    ['note', 'Notes'],
    ['image', 'Images'],
];

/** Maps a source's stored kind onto the card's filter buckets. */
const KIND_BUCKET = {
    journal_article: 'pdf',
    report: 'pdf',
    book: 'pdf',
    website: 'web',
    interview: 'note',
    other: 'note',
};

const KIND_GLYPH = {
    pdf: { icon: 'fPdf', className: 'text-file-pdf' },
    web: { icon: 'fHtml', className: 'text-file-web' },
    note: { icon: 'note', className: 'text-text-secondary' },
    image: { icon: 'image', className: 'text-file-html' },
};

function ResearchCard({ sources = [], onOpen, onAdd }) {
    const [filter, setFilter] = useState('all');

    const rows = sources.filter(
        (source) => filter === 'all' || KIND_BUCKET[source.kind] === filter,
    );

    return (
        <Card
            title="Research Library"
            action={
                <button
                    data-a="research-card:add"
                    onClick={onAdd}
                    className="flex items-center gap-1 rounded-control bg-brand-primary-subtle px-2 py-1 text-[11px] font-semibold text-brand-primary transition hover:bg-brand-primary-subtle/70"
                >
                    <Icon name="plus" size={12} strokeWidth={2.2} /> Add
                </button>
            }
        >
            <div className="flex gap-1 px-2.5 py-2">
                {FILTERS.map(([key, label]) => (
                    <button
                        key={key}
                        data-a={`research-card:filter:${key}`}
                        onClick={() => setFilter(key)}
                        className={`rounded-badge px-2 py-[3px] text-[10.5px] transition ${
                            filter === key
                                ? 'bg-brand-primary text-white'
                                : 'text-text-secondary hover:bg-bg-subtle'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {rows.length === 0 ? (
                <p className="px-3 py-4 text-[11px] leading-relaxed text-text-secondary">
                    {sources.length === 0
                        ? 'No sources yet. Add the first one to start this book’s bibliography.'
                        : `Nothing filed under ${FILTERS.find(([k]) => k === filter)[1]}.`}
                </p>
            ) : (
                <ul className="pb-2">
                    {rows.map((source) => {
                        const glyph =
                            KIND_GLYPH[KIND_BUCKET[source.kind] ?? 'note'] ??
                            KIND_GLYPH.note;

                        return (
                            <li key={source.id}>
                                <button
                                    data-a={`research-card:open:${source.id}`}
                                    onClick={onOpen}
                                    className="flex h-[42px] w-full items-center gap-2.5 px-3 text-left transition hover:bg-bg-subtle"
                                >
                                    <span className={`shrink-0 ${glyph.className}`}>
                                        <Icon name={glyph.icon} size={16} />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[10.5px] font-medium text-text-primary">
                                            {source.title}
                                        </span>
                                        <span className="block truncate text-[9.5px] text-text-secondary">
                                            {source.published || '—'}
                                            {source.verification !== 'verified' &&
                                                ` · ${source.verification}`}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Card>
    );
}

function PlaceholderCard({ title, what, needs }) {
    return (
        <Card title={title}>
            <div className="grid h-full place-items-center px-3 py-4 text-center">
                <div>
                    <p className="text-[11px] leading-relaxed text-text-secondary">
                        {what}
                    </p>
                    <p className="mt-2 text-[10px] leading-relaxed text-text-secondary/75">
                        Not built yet — needs {needs}. Showing a sample here
                        would misrepresent what the app can do.
                    </p>
                </div>
            </div>
        </Card>
    );
}

/** Small helper so the studio can load the library once and share it. */
export function useSources(bookId) {
    const [sources, setSources] = useState([]);

    useEffect(() => {
        if (!bookId) return;

        let cancelled = false;

        window.axios
            .get(`/books/${bookId}/sources`)
            .then((response) => !cancelled && setSources(response.data.sources))
            .catch(() => {});

        return () => {
            cancelled = true;
        };
    }, [bookId]);

    return sources;
}
