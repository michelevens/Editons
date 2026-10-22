import { useEffect, useRef, useState } from 'react';

/**
 * Import a manuscript: Markdown or Word into the open book's binder, or (on
 * the shelf) any of those or a .ecrivain file as a new book.
 *
 * The result is the server's import report, shown before anything else
 * happens: which chapters were made, how many pictures came with them, and
 * what the file held that the book could not take. An author who imports
 * 90,000 words needs to read that list now, not find the gaps at proof.
 */

const MAX_MB = 25;

export default function ImportDialog({ title, url, accept, hint, onClose, onDone, doneLabel = 'Done' }) {
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);
    const chooser = useRef(null);
    const done = useRef(null);

    // Focus moves into the dialog, and back to whatever opened it after.
    useEffect(() => {
        const opener = document.activeElement;
        chooser.current?.focus();

        return () => opener?.focus?.();
    }, []);

    useEffect(() => {
        if (result) done.current?.focus();
    }, [result]);

    const choose = (event) => {
        const chosen = event.target.files?.[0] ?? null;

        setError(null);
        setFile(chosen);

        // Said here rather than after a 25 MB upload fails.
        if (chosen && chosen.size > MAX_MB * 1024 * 1024) {
            setError(`That file is larger than ${MAX_MB} MB.`);
        }
    };

    const upload = async () => {
        if (!file || busy) return;

        setBusy(true);
        setError(null);

        try {
            const form = new FormData();
            form.append('file', file);
            const response = await window.axios.post(url, form, { headers: { Accept: 'application/json' } });

            setResult(response.data);
        } catch (failure) {
            setError(
                failure.response?.data?.errors?.file?.[0] ??
                    failure.response?.data?.message ??
                    'The file could not be imported. Try again.',
            );
        } finally {
            setBusy(false);
        }
    };

    const report = result?.report;
    const skipped = Object.entries(report?.skipped ?? {});

    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center bg-black/30 p-4"
            onMouseDown={(event) => event.target === event.currentTarget && !busy && onClose()}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="import-title"
                className="max-h-[90vh] w-full max-w-[520px] overflow-y-auto rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl"
                onKeyDown={(event) => event.key === 'Escape' && !busy && onClose()}
            >
                <h3 id="import-title" className="text-[15px] font-semibold text-text-primary">
                    {title}
                </h3>

                {!report ? (
                    <>
                        <p className="mt-1.5 text-[12.5px] text-text-secondary">{hint}</p>

                        <label className="mt-3 flex min-h-[110px] cursor-pointer flex-col items-center justify-center gap-1 rounded-control border border-dashed border-border-input bg-bg-subtle px-4 text-center text-[13px] text-text-secondary focus-within:border-brand-primary hover:border-brand-primary">
                            <span className="font-medium text-text-primary">
                                {file ? file.name : 'Choose a file'}
                            </span>
                            <span className="text-[12px]">
                                {file ? `${Math.max(1, Math.round(file.size / 1024)).toLocaleString()} KB` : `Up to ${MAX_MB} MB`}
                            </span>
                            <input
                                ref={chooser}
                                type="file"
                                accept={accept}
                                data-a="import:file"
                                onChange={choose}
                                className="sr-only"
                            />
                        </label>

                        {error && (
                            <p role="alert" className="mt-3 text-[12.5px] text-status-danger">
                                {error}
                            </p>
                        )}

                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={busy}
                                className="h-[34px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                data-a="import:upload"
                                onClick={upload}
                                disabled={!file || busy || (file && file.size > MAX_MB * 1024 * 1024)}
                                className="h-[34px] rounded-control bg-brand-primary px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                            >
                                {busy ? 'Importing…' : 'Import'}
                            </button>
                        </div>
                    </>
                ) : (
                    <div data-a="import:report" role="status">
                        <p className="mt-2 text-[13px] text-text-primary">
                            {result.book?.title ? (
                                <>
                                    <strong>{result.book.title}</strong>:{' '}
                                </>
                            ) : null}
                            {report.sections === 1 ? '1 chapter' : `${report.sections} chapters`} imported
                            {report.images > 0 &&
                                `, with ${report.images === 1 ? '1 picture' : `${report.images} pictures`}`}
                            .
                        </p>

                        {report.titles?.length > 0 && (
                            <ol className="mt-2 max-h-[160px] list-decimal overflow-y-auto rounded-control bg-bg-subtle py-2 pl-8 pr-3 text-[12.5px] text-text-body">
                                {report.titles.map((name, index) => (
                                    <li key={index}>{name}</li>
                                ))}
                            </ol>
                        )}

                        {skipped.length === 0 ? (
                            <p className="mt-3 text-[12.5px] text-status-success">Everything in the file was carried over.</p>
                        ) : (
                            <div className="mt-3 rounded-control bg-status-warning-bg px-3.5 py-2.5 text-[12.5px] text-status-warning">
                                <p className="font-semibold">Not carried over — add these by hand:</p>
                                <ul className="mt-1 list-disc pl-5">
                                    {skipped.map(([what, count]) => (
                                        <li key={what}>
                                            {what}: {count}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="mt-4 flex justify-end">
                            <button
                                ref={done}
                                type="button"
                                data-a="import:done"
                                onClick={() => onDone(result)}
                                className="h-[34px] rounded-control bg-brand-primary px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"
                            >
                                {doneLabel}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
