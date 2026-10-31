import { useEffect, useRef, useState } from 'react';
import Dialog from '../Dialog';
import { useI18n } from '@/i18n';

/**
 * Import a manuscript: Markdown or Word into the open book's binder, or (on
 * the shelf) any of those or a .ecrivain file as a new book.
 *
 * The result is the server's import report, shown before anything else
 * happens: which chapters were made, how many pictures came with them, and
 * what the file held that the book could not take. An author who imports
 * 90,000 words needs to read that list now, not find the gaps at proof.
 *
 * Two choices, both off unless the author makes them (ADR — AI-written
 * imports): "written with an AI tool", which saves each section's first
 * version as an AI draft so the book's AI disclosure counts it; and, for a
 * Markdown file, "Review sections", which lists the sections before
 * anything is written so each can be front matter, a part, a chapter or
 * back matter. Left alone, every section is a chapter, as before.
 */

const TYPES = ['front_matter', 'part', 'chapter', 'back_matter'];

const isMarkdown = (file) => /\.(md|markdown|txt)$/i.test(file?.name ?? '');

const MAX_MB = 25;

export default function ImportDialog({ title, url, accept, hint, onClose, onDone, doneLabel }) {
    const { t, formatNumber } = useI18n();
    const [file, setFile] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);
    const [aiWritten, setAiWritten] = useState(false);
    // null: not reviewed (every section a chapter); a list once reviewed.
    const [sections, setSections] = useState(null);
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
        setSections(null);

        // Said here rather than after a 25 MB upload fails.
        if (chosen && chosen.size > MAX_MB * 1024 * 1024) {
            setError(t('import.too_large', { mb: MAX_MB }));
        }
    };

    const upload = async () => {
        if (!file || busy) return;

        setBusy(true);
        setError(null);

        try {
            const form = new FormData();
            form.append('file', file);
            if (aiWritten) form.append('ai_written', '1');
            (sections ?? []).forEach((section) => form.append('types[]', section.type));
            const response = await window.axios.post(url, form, { headers: { Accept: 'application/json' } });

            setResult(response.data);
        } catch (failure) {
            setError(
                failure.response?.data?.errors?.file?.[0] ??
                    failure.response?.data?.message ??
                    t('import.failed'),
            );
        } finally {
            setBusy(false);
        }
    };

    const review = async () => {
        if (!file || busy) return;

        setBusy(true);
        setError(null);

        try {
            const form = new FormData();
            form.append('file', file);
            const { data } = await window.axios.post('/import/preview', form, { headers: { Accept: 'application/json' } });

            setSections(data.sections ?? []);
        } catch (failure) {
            setError(failure.response?.data?.errors?.file?.[0] ?? failure.response?.data?.message ?? t('import.failed'));
        } finally {
            setBusy(false);
        }
    };

    const setType = (index, type) => setSections((list) => list.map((s, i) => (i === index ? { ...s, type } : s)));

    const report = result?.report;
    const skipped = Object.entries(report?.skipped ?? {});

    return (
        <Dialog
            onClose={() => !busy && onClose()}
            aria-labelledby="import-title"
            className="max-h-[90vh] w-full max-w-[520px] overflow-y-auto rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl"
        >
            <h3 id="import-title" className="text-[15px] font-semibold text-text-primary">
                {title}
            </h3>

            {!report ? (
                <>
                    <p className="mt-1.5 text-[12.5px] text-text-secondary">{hint}</p>

                    <label className="mt-3 flex min-h-[110px] cursor-pointer flex-col items-center justify-center gap-1 rounded-control border border-dashed border-border-input bg-bg-subtle px-4 text-center text-[13px] text-text-secondary focus-within:border-brand-primary hover:border-brand-primary">
                        <span className="font-medium text-text-primary">
                            {file ? file.name : t('import.choose')}
                        </span>
                        <span className="text-[12px]">
                            {file ? t('import.size_kb', { kb: formatNumber(Math.max(1, Math.round(file.size / 1024))) }) : t('import.up_to', { mb: MAX_MB })}
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

                    <label className="mt-3 flex items-start gap-2 text-[12.5px] text-text-primary">
                        <input
                            type="checkbox"
                            data-a="import:ai-written"
                            checked={aiWritten}
                            onChange={(event) => setAiWritten(event.target.checked)}
                            className="mt-0.5"
                        />
                        <span>
                            {t('import.ai_written')}
                            <span className="block text-[12px] text-text-secondary">{t('import.ai_written_hint')}</span>
                        </span>
                    </label>

                    {sections && (
                        <div className="mt-3" data-a="import:sections">
                            <p className="text-[12.5px] text-text-secondary">
                                {sections.length > 0 ? t('import.review_hint') : t('import.review_none')}
                            </p>
                            {sections.length > 0 && (
                                <ol className="mt-2 max-h-[220px] space-y-1 overflow-y-auto rounded-control bg-bg-subtle p-2 text-[12.5px]">
                                    {sections.map((section, index) => (
                                        <li key={index} className="flex min-w-0 items-center gap-2">
                                            <span className="min-w-0 flex-1 truncate text-text-body" title={section.title}>
                                                {index + 1}. {section.title}
                                            </span>
                                            <select
                                                aria-label={t('import.type_of', { title: section.title })}
                                                data-a="import:section-type"
                                                value={section.type}
                                                onChange={(event) => setType(index, event.target.value)}
                                                className="h-[28px] shrink-0 rounded-control border border-border-input bg-bg-surface px-1.5 text-[12px] text-text-primary"
                                            >
                                                {TYPES.map((type) => (
                                                    <option key={type} value={type}>
                                                        {t(`import.type_${type}`)}
                                                    </option>
                                                ))}
                                            </select>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    )}

                    {error && (
                        <p role="alert" className="mt-3 text-[12.5px] text-status-danger">
                            {error}
                        </p>
                    )}

                    <div className="mt-4 flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={busy}
                            className="h-[34px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle disabled:opacity-50"
                        >
                            {t('common.cancel')}
                        </button>
                        {isMarkdown(file) && !sections && (
                            <button
                                type="button"
                                data-a="import:review"
                                onClick={review}
                                disabled={busy || file.size > MAX_MB * 1024 * 1024}
                                className="h-[34px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle disabled:opacity-50"
                            >
                                {t('import.review')}
                            </button>
                        )}
                        <button
                            type="button"
                            data-a="import:upload"
                            onClick={upload}
                            disabled={!file || busy || (file && file.size > MAX_MB * 1024 * 1024)}
                            className="h-[34px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50"
                        >
                            {busy ? t('import.importing') : t('import.import')}
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
                        {report.images > 0
                            ? t('import.result_with_pictures', { count: report.sections, images: report.images })
                            : t('import.result', { count: report.sections })}
                    </p>

                    {report.titles?.length > 0 && (
                        <ol className="mt-2 max-h-[160px] list-decimal overflow-y-auto rounded-control bg-bg-subtle py-2 pl-8 pr-3 text-[12.5px] text-text-body">
                            {report.titles.map((name, index) => (
                                <li key={index}>{name}</li>
                            ))}
                        </ol>
                    )}

                    {skipped.length === 0 ? (
                        <p className="mt-3 text-[12.5px] text-status-success">{t('import.all_carried')}</p>
                    ) : (
                        <div className="mt-3 rounded-control bg-status-warning-bg px-3.5 py-2.5 text-[12.5px] text-status-warning">
                            <p className="font-semibold">{t('import.not_carried')}</p>
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
                            className="h-[34px] rounded-control bg-brand-fill px-4 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"
                        >
                            {doneLabel ?? t('import.done')}
                        </button>
                    </div>
                </div>
            )}
        </Dialog>
    );
}
