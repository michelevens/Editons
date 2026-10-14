import { useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * The review board: every chapter in one of four states.
 *
 * Moving a card is a single PATCH, and the server returns the whole board
 * rather than the changed row — the counts in the column headers would
 * otherwise have to be recomputed on the client and could drift from what the
 * database says.
 */

const COLUMNS = [
    { key: 'draft', label: 'Draft', tint: 'bg-bg-subtle text-text-secondary' },
    { key: 'review', label: 'In review', tint: 'bg-status-info-bg text-status-info' },
    { key: 'revisions', label: 'Revisions', tint: 'bg-status-warning-bg text-status-warning' },
    { key: 'approved', label: 'Approved', tint: 'bg-status-success-bg text-status-success' },
];

export default function WorkflowBoard({ bookId, onOpenSection }) {
    const [chapters, setChapters] = useState([]);
    const [loading, setLoading] = useState(true);
    const [moving, setMoving] = useState(null);

    useEffect(() => {
        let cancelled = false;

        window.axios
            .get(`/books/${bookId}/workflow`)
            .then((response) => !cancelled && setChapters(response.data.chapters))
            .finally(() => !cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [bookId]);

    const move = async (chapter, direction) => {
        const index = COLUMNS.findIndex((column) => column.key === chapter.status);
        const next = COLUMNS[index + direction];

        if (!next) return;

        setMoving(chapter.id);

        try {
            const response = await window.axios.patch(
                `/sections/${chapter.id}/status`,
                { status: next.key },
            );
            setChapters(response.data.chapters);
        } finally {
            setMoving(null);
        }
    };

    if (loading) {
        return (
            <p className="p-8 text-center text-[13px] text-text-secondary">Loading…</p>
        );
    }

    return (
        <div className="mx-auto max-w-[1040px] p-8">
            <header className="mb-6">
                <h2 className="font-display text-[24px] font-bold tracking-[-0.03em] text-text-primary">
                    Review Workflow
                </h2>
                <p className="mt-1.5 text-[14px] text-text-secondary">
                    Where each chapter stands. Moving a card here is what the
                    Overview checklist counts.
                </p>
            </header>

            {chapters.length === 0 ? (
                <p className="rounded-panel border border-dashed border-border-default p-8 text-center text-[13px] text-text-secondary">
                    No chapters yet.
                </p>
            ) : (
                <div className="grid gap-[14px] md:grid-cols-2 xl:grid-cols-4">
                    {COLUMNS.map((column, columnIndex) => {
                        const cards = chapters.filter(
                            (chapter) => chapter.status === column.key,
                        );

                        return (
                            <section
                                key={column.key}
                                className="rounded-panel border border-border-default bg-bg-surface"
                            >
                                <header className="flex items-center justify-between border-b border-border-divider px-3.5 py-3">
                                    <span className="text-[13px] font-semibold text-text-primary">
                                        {column.label}
                                    </span>
                                    <span
                                        className={`rounded-badge px-2 py-0.5 text-[11px] font-semibold ${column.tint}`}
                                    >
                                        {cards.length}
                                    </span>
                                </header>

                                <div className="space-y-2 p-2.5">
                                    {cards.length === 0 ? (
                                        <p className="px-1 py-3 text-center text-[11.5px] text-text-secondary">
                                            Nothing here.
                                        </p>
                                    ) : (
                                        cards.map((chapter) => (
                                            <article
                                                key={chapter.id}
                                                className="rounded-tile border border-border-default p-2.5"
                                            >
                                                <button
                                                    data-a={`workflow:open:${chapter.id}`}
                                                    onClick={() => onOpenSection?.(chapter.id)}
                                                    className="block w-full text-left"
                                                >
                                                    <span className="block truncate text-[12.5px] font-semibold text-text-primary">
                                                        {chapter.title}
                                                    </span>
                                                    <span className="mt-0.5 block text-[11px] text-text-secondary">
                                                        {chapter.word_count.toLocaleString()}{' '}
                                                        words · {chapter.updated_at}
                                                    </span>
                                                </button>

                                                <div className="mt-2 flex items-center justify-between">
                                                    <button
                                                        data-a={`workflow:back:${chapter.id}`}
                                                        onClick={() => move(chapter, -1)}
                                                        disabled={
                                                            columnIndex === 0 ||
                                                            moving === chapter.id
                                                        }
                                                        aria-label="Move back"
                                                        className="rounded p-1 text-text-secondary transition hover:bg-bg-subtle disabled:opacity-30"
                                                    >
                                                        <Icon name="left" size={14} />
                                                    </button>

                                                    <button
                                                        data-a={`workflow:forward:${chapter.id}`}
                                                        onClick={() => move(chapter, 1)}
                                                        disabled={
                                                            columnIndex === COLUMNS.length - 1 ||
                                                            moving === chapter.id
                                                        }
                                                        aria-label="Move forward"
                                                        className="rounded p-1 text-text-secondary transition hover:bg-bg-subtle disabled:opacity-30"
                                                    >
                                                        <Icon name="right" size={14} />
                                                    </button>
                                                </div>
                                            </article>
                                        ))
                                    )}
                                </div>
                            </section>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
