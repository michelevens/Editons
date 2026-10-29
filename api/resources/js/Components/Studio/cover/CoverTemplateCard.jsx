import { useState } from 'react';

/**
 * Cover templates in Cover Studio (CoverTemplates on the server): what this
 * book's cover follows, with "Customise this book's cover" (detach) and
 * "Restore the previous cover"; "Save as cover template" (with or without
 * the book's pictures); applying one of the account's templates to this
 * book, with the choice an uploaded finished cover needs; and updating a
 * template from this book, which rewrites the books that follow it.
 */

const control = 'rounded-control border border-border-input bg-bg-surface px-2 py-1 text-[12px] focus:border-brand-primary focus:ring-0';
const primary = 'rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50';
const chip = 'rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40 disabled:opacity-50';

export default function CoverTemplateCard({ bookId, template, onChanged, onNotify }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [name, setName] = useState('');
    const [images, setImages] = useState(false);
    const [chosen, setChosen] = useState('');
    const [artwork, setArtwork] = useState('');
    const [reason, setReason] = useState('');

    if (!template) return null;

    const { following, templates = [], uploaded = {}, restorable, can_manage: canManage } = template;
    const hasUpload = uploaded.front || uploaded.back;
    const pick = chosen || templates[0]?.id || '';

    const run = async (request, done) => {
        setBusy(true);

        try {
            const response = await request();
            const failed = response?.data?.results?.find((r) => !r.ok);

            if (failed) onNotify?.(failed.message);
            else if (done) onNotify?.(done);

            await onChanged?.();
        } catch (e) {
            const errors = e.response?.data?.errors;
            onNotify?.((errors && Object.values(errors)[0]?.[0]) ?? e.response?.data?.message ?? 'That did not work.');
        } finally {
            setBusy(false);
        }
    };

    const detach = () => run(() => window.axios.post(`/books/${bookId}/cover-template/detach`), 'This cover is now the book’s own.');
    const restore = () => {
        if (!window.confirm('Put back the cover this book had before the template? The template’s design leaves this book.')) return;
        run(() => window.axios.post(`/books/${bookId}/cover-template/restore`), 'The previous cover is back.');
    };

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface px-3 py-2" data-a="cover:template">
            {following ? (
                <div className="flex flex-wrap items-center gap-2" data-a="cover:template:following">
                    <p className="min-w-0 flex-1 text-[12px] text-text-body">
                        This cover follows the template <span className="font-semibold">“{following.name}”</span>
                        {following.current ? '' : ' (an older version)'}. Its title, author and other details are the book’s own; to change the design for this book alone, customise it.
                    </p>
                    <button type="button" data-a="cover:template:detach" disabled={busy} onClick={detach} className={chip}>
                        Customise this book’s cover
                    </button>
                </div>
            ) : (
                <button type="button" aria-expanded={open} data-a="cover:template:toggle" onClick={() => setOpen(!open)} className="flex w-full items-center justify-between text-left text-[12px] font-semibold text-text-primary">
                    <span>Cover templates{templates.length ? ` (${templates.length})` : ''}</span>
                    <span aria-hidden="true" className="text-text-secondary">{open ? '−' : '+'}</span>
                </button>
            )}

            {restorable && (
                <button type="button" data-a="cover:template:restore" disabled={busy} onClick={restore} className="mt-1.5 text-[11.5px] font-semibold text-brand-primary hover:underline">
                    Restore the previous cover
                </button>
            )}

            {open && !following && canManage && (
                <div className="mt-2 space-y-3 border-t border-border-default pt-2">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        data-a="cover:template:save"
                        onSubmit={(e) => {
                            e.preventDefault();
                            run(() => window.axios.post(`/books/${bookId}/cover-template`, { name, include_images: images }), `Saved “${name}”. This book follows it.`).then(() => setName(''));
                        }}
                    >
                        <label className="text-[12px] text-text-body">
                            Save this cover as a template
                            <input value={name} required minLength={2} maxLength={80} data-a="cover:template:name" onChange={(e) => setName(e.target.value)} placeholder="Series cover" className={`${control} mt-1 block w-[220px] max-w-full`} />
                        </label>
                        <label className="flex items-center gap-1.5 text-[12px] text-text-body">
                            <input type="checkbox" checked={images} data-a="cover:template:images" onChange={(e) => setImages(e.target.checked)} />
                            Include its pictures and artwork
                        </label>
                        <button type="submit" disabled={busy || name.trim().length < 2} data-a="cover:template:save-run" className={primary}>
                            Save template
                        </button>
                        <p className="w-full text-[11px] text-text-secondary">
                            Every panel’s layers, backgrounds, spine and faces. Title, author, series number, copy and ISBN stay bound: each book fills in its own.
                        </p>
                    </form>

                    {templates.length > 0 && (
                        <div className="space-y-2" data-a="cover:template:apply">
                            <label className="block text-[12px] text-text-body">
                                Use a template on this book
                                <select value={pick} data-a="cover:template:pick" onChange={(e) => setChosen(Number(e.target.value))} className={`${control} mt-1 block max-w-full`}>
                                    {templates.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name} — {t.summary}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {hasUpload && (
                                <ArtworkChoice uploaded={uploaded} value={artwork} onChange={setArtwork} name={`cover-artwork-${bookId}`} />
                            )}
                            {template.house && (
                                <input value={reason} placeholder="Reason (kept in the audit log)" data-a="cover:template:reason" onChange={(e) => setReason(e.target.value)} className={`${control} block w-full`} />
                            )}
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    data-a="cover:template:apply-run"
                                    disabled={busy || !pick || (hasUpload && !artwork)}
                                    onClick={() => run(() => window.axios.post(`/cover-templates/${pick}/apply`, { books: [bookId], artwork: artwork || null, reason: reason || null }), 'The template is on this cover.')}
                                    className={primary}
                                >
                                    Apply to this book
                                </button>
                                <button
                                    type="button"
                                    data-a="cover:template:update"
                                    disabled={busy || !pick}
                                    onClick={() => {
                                        const t = templates.find((x) => x.id === Number(pick));
                                        if (!window.confirm(`Replace “${t?.name}” with this book’s cover? ${t?.books ?? 0} book(s) following it will change.`)) return;
                                        run(() => window.axios.put(`/cover-templates/${pick}`, { book: bookId }), 'Template updated from this book.');
                                    }}
                                    className={chip}
                                >
                                    Update it from this book
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

/** What happens to an uploaded finished front or back: nothing, until chosen. */
export function ArtworkChoice({ uploaded, value, onChange, name, allowNone = false }) {
    const what = uploaded === true ? 'Some of these books have an uploaded finished cover' : `This book has an uploaded finished ${uploaded.front && uploaded.back ? 'front and back' : uploaded.front ? 'front' : 'back'}`;
    const options = [
        ...(allowNone ? [['', 'Leave those books as they are']] : []),
        ['keep', 'Keep it as the background, the template’s type over it'],
        ['replace', 'Replace it with the template’s design (it can be restored)'],
    ];

    return (
        <fieldset className="rounded-control border border-status-warning/40 bg-status-warning-bg px-2.5 py-2" data-a="cover:template:artwork">
            <legend className="px-1 text-[11.5px] font-semibold text-status-warning">{what}</legend>
            {options.map(([key, label]) => (
                <label key={key || 'none'} className="flex items-start gap-1.5 py-0.5 text-[12px] text-text-body">
                    <input type="radio" name={name} value={key} checked={value === key} data-a={`cover:template:artwork:${key || 'none'}`} onChange={() => onChange(key)} className="mt-0.5" />
                    {label}
                </label>
            ))}
        </fieldset>
    );
}
