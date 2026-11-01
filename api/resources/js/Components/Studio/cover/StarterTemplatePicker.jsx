import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * Starter templates in Cover Studio (ADR — AI research, source-backed
 * fact-checking, cover templates): a designed typographic cover with no
 * artwork. Pick one, let the AI suggest palette, motif and type for the
 * book, tweak, then use it. Applying replaces the cover design (the
 * previous one can be restored) and leaves everything editable.
 */
export default function StarterTemplatePicker({ bookId, title = '', onApplied, onNotify }) {
    const { t } = useI18n();
    const [catalogue, setCatalogue] = useState(null);
    const [open, setOpen] = useState(false);
    const [choice, setChoice] = useState(null); // {template, palette, pairing, motif}
    const [rationale, setRationale] = useState(null);
    const [busy, setBusy] = useState(null); // 'suggest' | 'apply' | null

    useEffect(() => {
        if (!open || catalogue) return;
        window.axios.get('/cover-starters').then((res) => setCatalogue(res.data));
    }, [open, catalogue]);

    const pick = (template) => setChoice({ template: template.key, palette: template.palette, pairing: template.pairing, motif: template.motif });

    const suggest = async () => {
        setBusy('suggest');

        try {
            const { data } = await window.axios.post(`/books/${bookId}/cover/starter/suggest`);
            const s = data.suggestion;
            setChoice({ template: s.template, palette: s.palette, pairing: s.pairing, motif: s.motif });
            setRationale(s.rationale);
        } catch (err) {
            onNotify?.(err.response?.data?.message ?? err.response?.data?.errors?.credits?.[0] ?? t('starter.failed'));
        } finally {
            setBusy(null);
        }
    };

    const apply = async (replace = false) => {
        setBusy('apply');

        try {
            const { data } = await window.axios.post(`/books/${bookId}/cover/starter`, { ...choice, replace });
            const failing = (data.contrast ?? []).filter((row) => !row.ok).length;
            onNotify?.(failing ? t('starter.applied_contrast', { count: failing }) : t('starter.applied'));
            onApplied?.();
        } catch (err) {
            const cover = err.response?.data?.errors?.cover?.[0];
            if (cover && !replace && confirm(cover)) return apply(true);
            onNotify?.(err.response?.data?.errors?.cover_template?.[0] ?? cover ?? err.response?.data?.message ?? t('starter.failed'));
        } finally {
            setBusy(null);
        }
    };

    const palette = (key) => catalogue?.palettes.find((p) => p.key === key);
    const motifSvg = (key, color) => (catalogue?.motifs.find((m) => m.key === key)?.svg ?? '').replaceAll('#334155', color);

    return (
        <details
            data-a="starter:panel"
            className="rounded-tile border border-border-default bg-bg-surface px-3 py-2"
            open={open}
            onToggle={(e) => setOpen(e.currentTarget.open)}
        >
            <summary className="cursor-pointer text-[12.5px] font-semibold text-text-primary">{t('starter.title')}</summary>
            <p className="mt-1 text-[11.5px] text-text-secondary">{t('starter.intro')}</p>

            {!catalogue ? (
                <p className="mt-2 text-[11.5px] text-text-secondary">{t('common.loading')}</p>
            ) : (
                <>
                    <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4 xl:grid-cols-8">
                        {catalogue.templates.map((tpl) => {
                            const chosen = choice?.template === tpl.key;
                            const p = palette(chosen ? choice.palette : tpl.palette);
                            const motif = chosen ? choice.motif : tpl.motif;

                            return (
                                <button
                                    key={tpl.key}
                                    type="button"
                                    data-a={`starter:template:${tpl.key}`}
                                    aria-pressed={chosen}
                                    onClick={() => pick(tpl)}
                                    className={`rounded-control border p-1.5 text-left transition ${chosen ? 'border-brand-primary ring-2 ring-brand-primary/30' : 'border-border-input hover:border-brand-primary/40'}`}
                                >
                                    <Preview tpl={tpl} palette={p} motifSvg={motif && motif !== 'none' ? motifSvg(motif, p.accent) : ''} title={title} />
                                    <span className="mt-1 block truncate text-[11px] font-semibold text-text-primary">{t(`starter.tpl_${tpl.key}`)}</span>
                                </button>
                            );
                        })}
                    </div>

                    {choice && (
                        <div className="mt-3 flex flex-wrap items-end gap-2">
                            <Select label={t('starter.palette')} value={choice.palette} a="starter:palette" onChange={(v) => setChoice({ ...choice, palette: v })}>
                                {catalogue.palettes.map((p) => <option key={p.key} value={p.key}>{t(`starter.palette_${p.key}`)}</option>)}
                            </Select>
                            <Select label={t('starter.pairing')} value={choice.pairing} a="starter:pairing" onChange={(v) => setChoice({ ...choice, pairing: v })}>
                                {catalogue.pairings.map((p) => <option key={p.key} value={p.key}>{p.title} + {p.text}</option>)}
                            </Select>
                            <Select label={t('starter.motif')} value={choice.motif ?? 'none'} a="starter:motif" onChange={(v) => setChoice({ ...choice, motif: v })}>
                                <option value="none">{t('starter.motif_none')}</option>
                                {catalogue.motifs.map((m) => <option key={m.key} value={m.key}>{t(`starter.motif_${m.key}`)}</option>)}
                            </Select>
                        </div>
                    )}

                    {rationale && <p data-a="starter:rationale" className="mt-2 text-[11.5px] italic text-text-secondary">{rationale}</p>}

                    <div className="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            data-a="starter:suggest"
                            disabled={busy !== null}
                            onClick={suggest}
                            className="min-h-[34px] rounded-control border border-brand-primary px-3 text-[12px] font-semibold text-brand-primary disabled:opacity-50"
                        >
                            {busy === 'suggest' ? t('starter.suggesting') : t('starter.suggest')}
                        </button>
                        <button
                            type="button"
                            data-a="starter:apply"
                            disabled={!choice || busy !== null}
                            onClick={() => apply()}
                            className="min-h-[34px] rounded-control bg-brand-fill px-3 text-[12px] font-semibold text-white disabled:opacity-50"
                        >
                            {busy === 'apply' ? t('starter.applying') : t('starter.apply')}
                        </button>
                    </div>
                    <p className="mt-1.5 text-[10.5px] text-text-secondary">{t('starter.note')}</p>
                </>
            )}
        </details>
    );
}

function Preview({ tpl, palette, motifSvg, title }) {
    const box = tpl.boxes;
    const pct = (v) => `${v * 100}%`;

    return (
        <div
            aria-hidden="true"
            className="relative w-full overflow-hidden rounded-[3px]"
            style={{ aspectRatio: '5 / 8', background: `linear-gradient(160deg, ${palette.from}, ${palette.to})`, color: palette.ink }}
        >
            {motifSvg && (
                <div
                    className="absolute"
                    style={{ left: pct(box.motif_box.x), top: pct(box.motif_box.y), width: pct(box.motif_box.width), aspectRatio: '1 / 1' }}
                    dangerouslySetInnerHTML={{ __html: motifSvg }}
                />
            )}
            <div
                className="absolute px-[8%] font-serif text-[11px] font-bold leading-tight"
                style={{ top: pct(box.title.y), left: 0, right: 0, textAlign: tpl.align }}
            >
                {title || 'Title'}
            </div>
            <div className="absolute px-[8%] text-[6px]" style={{ top: pct(box.author.y), left: 0, right: 0, textAlign: tpl.align }}>
                ———
            </div>
        </div>
    );
}

function Select({ label, value, onChange, a, children }) {
    return (
        <label className="flex min-w-0 flex-col text-[11px] font-semibold text-text-secondary">
            {label}
            <select
                value={value}
                data-a={a}
                onChange={(e) => onChange(e.target.value)}
                className="mt-0.5 min-w-0 max-w-full rounded-control border-border-input text-[12px] focus:border-brand-primary focus:ring-brand-primary"
            >
                {children}
            </select>
        </label>
    );
}
