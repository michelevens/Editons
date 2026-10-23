import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * The author's notes about the book.
 *
 * A scratchpad for the decisions that govern the writing — who the reader is,
 * what the tone should be — kept beside the manuscript rather than in a
 * separate document nobody opens. Not versioned: a history of a scratchpad is
 * noise.
 */
export default function NotesPanel({ bookId, onNotify }) {
    const [notes, setNotes] = useState(null);
    const [status, setStatus] = useState('idle');
    const timer = useRef(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/notes`);
        setNotes(response.data.notes ?? '');
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const change = (value) => {
        setNotes(value);
        setStatus('dirty');

        clearTimeout(timer.current);

        timer.current = setTimeout(async () => {
            setStatus('saving');

            try {
                await window.axios.put(`/books/${bookId}/notes`, { notes: value });
                setStatus('saved');
            } catch {
                // Said out loud, like the manuscript's own save status: a
                // writer who believes their notes are saved when they are not
                // is the failure worth avoiding.
                setStatus('error');
                onNotify?.('Those notes were not saved.');
            }
        }, 800);
    };

    if (notes === null) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex items-center gap-3 border-b border-border-default px-4 py-3">
                <div>
                    <h2 className="text-[15px] font-semibold text-text-primary">Notes</h2>
                    <p className="text-[11.5px] text-text-secondary">
                        Yours alone — not part of the manuscript, and never exported
                    </p>
                </div>

                <span
                    data-a="notes:status"
                    className={`ml-auto text-[11.5px] ${
                        status === 'error' ? 'font-semibold text-status-danger' : 'text-text-secondary'
                    }`}
                >
                    {{ idle: '', dirty: 'Unsaved', saving: 'Saving…', saved: 'Saved', error: 'Not saved' }[status]}
                </span>
            </header>

            <div className="min-h-0 flex-1 p-4">
                <textarea
                    value={notes}
                    data-a="notes:body"
                    placeholder={'Who is this book for?\nWhat should the tone be?\nAnything you keep forgetting.'}
                    onChange={(event) => change(event.target.value)}
                    className="h-full w-full resize-none rounded-tile border border-border-input p-3 font-manuscript text-[13.5px] leading-relaxed placeholder:text-text-secondary focus:border-brand-primary focus:ring-0"
                />
            </div>
        </div>
    );
}
