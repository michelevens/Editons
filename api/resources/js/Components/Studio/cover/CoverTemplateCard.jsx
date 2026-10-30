import { useState } from 'react';
import { t, useI18n } from '../../../i18n';

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
    useI18n();
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
            onNotify?.((errors && Object.values(errors)[0]?.[0]) ?? e.response?.data?.message ?? t('cover.failed'));
        } finally {
            setBusy(false);
        }
    };

    const detach = () => run(() => window.axios.post(`/books/${bookId}/cover-template/detach`), t('cover.tpl_detached'));
    const restore = () => {
        if (!window.confirm(t('cover.tpl_confirm_restore'))) return;
        run(() => window.axios.post(`/books/${bookId}/cover-template/restore`), t('cover.tpl_restored'));
    };

    return (
        <section className="rounded-tile border border-border-default bg-bg-surface px-3 py-2" data-a="cover:template">
            {following ? (
                <div className="flex flex-wrap items-center gap-2" data-a="cover:template:following">
                    <p className="min-w-0 flex-1 text-[12px] text-text-body">
                        {t('cover.tpl_follows')} <span className="font-semibold">{t('cover.tpl_quoted', { name: following.name })}</span>
                        {following.current ? '' : t('cover.tpl_older')}{t('cover.tpl_follows_after')}
                    </p>
                    <button type="button" data-a="cover:template:detach" disabled={busy} onClick={detach} className={chip}>
                        {t('cover.tpl_detach')}
                    </button>
                </div>
            ) : (
                <button type="button" aria-expanded={open} data-a="cover:template:toggle" onClick={() => setOpen(!open)} className="flex w-full items-center justify-between text-left text-[12px] font-semibold text-text-primary">
                    <span>{templates.length ? t('cover.tpl_heading_count', { count: templates.length }) : t('cover.tpl_heading')}</span>
                    <span aria-hidden="true" className="text-text-secondary">{open ? '−' : '+'}</span>
                </button>
            )}

            {restorable && (
                <button type="button" data-a="cover:template:restore" disabled={busy} onClick={restore} className="mt-1.5 text-[11.5px] font-semibold text-brand-primary hover:underline">
                    {t('cover.tpl_restore')}
                </button>
            )}

            {open && !following && canManage && (
                <div className="mt-2 space-y-3 border-t border-border-default pt-2">
                    <form
                        className="flex flex-wrap items-end gap-2"
                        data-a="cover:template:save"
                        onSubmit={(e) => {
                            e.preventDefault();
                            run(() => window.axios.post(`/books/${bookId}/cover-template`, { name, include_images: images }), t('cover.tpl_saved', { name })).then(() => setName(''));
                        }}
                    >
                        <label className="text-[12px] text-text-body">
                            {t('cover.tpl_save_label')}
                            <input value={name} required minLength={2} maxLength={80} data-a="cover:template:name" onChange={(e) => setName(e.target.value)} placeholder={t('cover.tpl_name_placeholder')} className={`${control} mt-1 block w-[220px] max-w-full`} />
                        </label>
                        <label className="flex items-center gap-1.5 text-[12px] text-text-body">
                            <input type="checkbox" checked={images} data-a="cover:template:images" onChange={(e) => setImages(e.target.checked)} />
                            {t('cover.tpl_include_images')}
                        </label>
                        <button type="submit" disabled={busy || name.trim().length < 2} data-a="cover:template:save-run" className={primary}>
                            {t('cover.tpl_save')}
                        </button>
                        <p className="w-full text-[11px] text-text-secondary">
                            {t('cover.tpl_save_help')}
                        </p>
                    </form>

                    {templates.length > 0 && (
                        <div className="space-y-2" data-a="cover:template:apply">
                            <label className="block text-[12px] text-text-body">
                                {t('cover.tpl_use_label')}
                                <select value={pick} data-a="cover:template:pick" onChange={(e) => setChosen(Number(e.target.value))} className={`${control} mt-1 block max-w-full`}>
                                    {templates.map((tpl) => (
                                        <option key={tpl.id} value={tpl.id}>
                                            {tpl.name} — {tpl.summary}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {hasUpload && (
                                <ArtworkChoice uploaded={uploaded} value={artwork} onChange={setArtwork} name={`cover-artwork-${bookId}`} />
                            )}
                            {template.house && (
                                <input value={reason} placeholder={t('cover.tpl_reason')} data-a="cover:template:reason" onChange={(e) => setReason(e.target.value)} className={`${control} block w-full`} />
                            )}
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    data-a="cover:template:apply-run"
                                    disabled={busy || !pick || (hasUpload && !artwork)}
                                    onClick={() => run(() => window.axios.post(`/cover-templates/${pick}/apply`, { books: [bookId], artwork: artwork || null, reason: reason || null }), t('cover.tpl_applied'))}
                                    className={primary}
                                >
                                    {t('cover.tpl_apply')}
                                </button>
                                <button
                                    type="button"
                                    data-a="cover:template:update"
                                    disabled={busy || !pick}
                                    onClick={() => {
                                        const tpl = templates.find((x) => x.id === Number(pick));
                                        if (!window.confirm(t('cover.tpl_confirm_update', { name: tpl?.name, count: tpl?.books ?? 0 }))) return;
                                        run(() => window.axios.put(`/cover-templates/${pick}`, { book: bookId }), t('cover.tpl_updated'));
                                    }}
                                    className={chip}
                                >
                                    {t('cover.tpl_update')}
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
    useI18n();
    const what = uploaded === true
        ? t('cover.tpl_upload_some')
        : uploaded.front && uploaded.back
          ? t('cover.tpl_upload_both')
          : uploaded.front
            ? t('cover.tpl_upload_front')
            : t('cover.tpl_upload_back');
    const options = [
        ...(allowNone ? [['', t('cover.tpl_artwork_none')]] : []),
        ['keep', t('cover.tpl_artwork_keep')],
        ['replace', t('cover.tpl_artwork_replace')],
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
