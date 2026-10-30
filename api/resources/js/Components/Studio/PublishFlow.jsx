import { useCallback, useEffect, useRef, useState } from 'react';
import KdpGuideView from '@/Components/Publish/KdpGuideView';
import { formatMoney, t, useI18n } from '../../i18n';
import Icon from './Icon';

/**
 * Publish in three steps: Check · Choose channels · Publish.
 *
 * 1. Check runs every readiness check the studio has for the channels
 *    chosen, as one green / amber / red answer with a Fix link per problem
 *    straight to the pane that fixes it, and "Regenerate files" when the
 *    files are older than the manuscript.
 * 2. Choose channels says plainly what each one does: automatic (our
 *    store, Lulu once connected), guided (Amazon KDP, which has no
 *    publishing API), a spreadsheet (Google Play's bulk upload), or not yet
 *    (Apple Books).
 * 3. Publish does it: on sale in one click, the files to Lulu, the KDP
 *    guided page with a Copy button per field, Google's bulk upload, and
 *    "I've submitted it" with the ASIN.
 *
 * App\Http\Controllers\PublishFlowController; docs/PUBLISHING-FLOW.md.
 */
const steps = () => [
    ['check', t('publish.step_check'), t('publish.step_check_short')],
    ['channels', t('publish.step_channels'), t('publish.step_channels_short')],
    ['publish', t('publish.step_publish'), t('publish.step_publish_short')],
];

export default function PublishFlow({ bookId, onNotify, onNavigate }) {
    useI18n();
    const [data, setData] = useState(null);
    const [step, setStep] = useState('check');
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState(null);
    const [waitingSince, setWaitingSince] = useState(null);
    const alive = useRef(true);

    const apply = useCallback((payload) => {
        if (alive.current) setData(payload);
    }, []);

    const load = useCallback(async () => {
        try {
            apply((await window.axios.get(`/books/${bookId}/publish-flow`)).data);
            setError(null);
        } catch (e) {
            setError(e.response?.status === 403 ? t('publish.flow_forbidden') : t('publish.flow_load_failed'));
        }
    }, [bookId, apply]);

    useEffect(() => {
        alive.current = true;
        load();

        return () => {
            alive.current = false;
        };
    }, [load]);

    // After "Regenerate files", look again every few seconds until the
    // files are fresh, for at most the export timeout (half an hour).
    useEffect(() => {
        if (!waitingSince || !data?.check.stale) {
            if (waitingSince && data && !data.check.stale) setWaitingSince(null);

            return undefined;
        }

        if (Date.now() - waitingSince > 35 * 60 * 1000) {
            setWaitingSince(null);

            return undefined;
        }

        const timer = setTimeout(load, 8000);

        return () => clearTimeout(timer);
    }, [waitingSince, data, load]);

    const act = async (key, request, message = null) => {
        setBusy(key);

        try {
            const response = await request();
            apply(response.data);
            const said = message ?? response.data.message;
            if (said) onNotify?.(said);

            return response.data;
        } catch (e) {
            const errors = e.response?.data?.errors;
            onNotify?.(errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message ?? t('publish.flow_failed')));

            return null;
        } finally {
            setBusy(null);
        }
    };

    const regenerate = () =>
        act('regenerate', () => window.axios.post(`/books/${bookId}/publish-flow/regenerate`)).then((result) => {
            if (result?.results?.length) setWaitingSince(Date.now());
        });

    const fix = (pane) => {
        if (pane === 'regenerate') return regenerate();
        if (pane === 'channels') return setStep('channels');

        return onNavigate?.(pane);
    };

    if (error && !data) {
        return <p className="p-6 text-center text-[12.5px] text-text-secondary">{error}</p>;
    }

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">{t('common.loading')}</p>;
    }

    const { check } = data;

    return (
        <div className="flex h-full min-h-0 flex-col" data-a="publish-flow">
            <header className="border-b border-border-default px-4 py-3">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-[15px] font-semibold text-text-primary">{t('publish.flow_title')}</h2>
                    <Summary check={check} compact />
                </div>
                <nav aria-label={t('publish.flow_steps')} className="mt-2.5">
                    <ol className="grid grid-cols-3 gap-1.5">
                        {steps().map(([key, label, short], index) => (
                            <li key={key}>
                                <button
                                    type="button"
                                    data-a={`publish-flow:step:${key}`}
                                    aria-current={step === key ? 'step' : undefined}
                                    onClick={() => setStep(key)}
                                    className={`flex w-full items-center gap-1.5 rounded-control px-2 py-2 text-left text-[12px] font-semibold transition sm:text-[12.5px] ${
                                        step === key ? 'bg-brand-fill text-white' : 'border border-border-input text-text-body hover:border-brand-primary/40'
                                    }`}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={`grid h-5 w-5 shrink-0 place-items-center rounded-full text-[11px] ${step === key ? 'bg-white/25' : 'bg-bg-subtle'}`}
                                    >
                                        {index + 1}
                                    </span>
                                    <span className="min-w-0 truncate">
                                        <span className="sm:hidden">{short}</span>
                                        <span className="max-sm:hidden">{label}</span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </nav>
            </header>

            <div className="min-h-0 flex-1 overflow-auto p-4">
                {step === 'check' && (
                    <CheckStep data={data} busy={busy} waiting={Boolean(waitingSince)} onFix={fix} onRegenerate={regenerate} onNext={() => setStep('channels')} />
                )}
                {step === 'channels' && (
                    <ChannelsStep
                        data={data}
                        busy={busy}
                        onChoose={(card, on) => act(`card:${card}`, () => window.axios.post(`/books/${bookId}/publish-flow/channels`, { card, on }))}
                        onNext={() => setStep('publish')}
                    />
                )}
                {step === 'publish' && <PublishStep bookId={bookId} data={data} busy={busy} act={act} onFix={fix} onBack={() => setStep('channels')} />}
            </div>
        </div>
    );
}

// ── Step 1 ──────────────────────────────────────────────────────────────

const SUMMARY = {
    green: ['bg-status-success-bg text-status-success', 'publish.summary_green'],
    amber: ['bg-status-warning-bg text-status-warning', 'publish.summary_amber'],
    red: ['bg-status-danger-bg text-status-danger', 'publish.summary_red'],
};

function Summary({ check, compact = false }) {
    const [tone, labelKey] = SUMMARY[check.status];
    const label = t(labelKey);

    if (compact) {
        return (
            <span data-a="publish-flow:summary-badge" className={`rounded-full px-2 py-0.5 text-[11.5px] font-semibold ${tone}`}>
                {label}
            </span>
        );
    }

    return (
        <div role="status" data-a="publish-flow:summary" data-status={check.status} className={`rounded-tile p-3 ${tone}`}>
            <p className="text-[14px] font-semibold">{check.summary}</p>
            <p className="mt-0.5 text-[12px] opacity-90">
                {t('publish.summary_counts', { fail: check.counts.fail, warn: check.counts.warn, pass: check.counts.pass })}
            </p>
        </div>
    );
}

const ITEM = {
    fail: ['✕', 'text-status-danger', 'publish.item_fail'],
    warn: ['!', 'text-status-warning', 'publish.item_warn'],
    pass: ['✓', 'text-status-success', 'publish.item_pass'],
};

function CheckStep({ data, busy, waiting, onFix, onRegenerate, onNext }) {
    const { check } = data;
    const [showPassed, setShowPassed] = useState(false);
    const groups = Object.entries(check.groups)
        .map(([key, label]) => [key, label, check.items.filter((item) => item.group === key && (showPassed || item.status !== 'pass'))])
        .filter(([, , items]) => items.length > 0);

    return (
        <div className="space-y-4">
            <Summary check={check} />

            {check.stale && (
                <div className="flex flex-wrap items-center gap-3 rounded-tile border border-border-default bg-bg-surface p-3">
                    <p className="min-w-0 flex-1 text-[12.5px] text-text-body">
                        {waiting ? t('publish.files_making') : t('publish.files_stale')}
                    </p>
                    <button
                        type="button"
                        data-a="publish-flow:regenerate"
                        disabled={busy === 'regenerate' || waiting || !data.can.update}
                        onClick={onRegenerate}
                        className="inline-flex items-center gap-1.5 rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                    >
                        <Icon name="reset" size={13} strokeWidth={2} />
                        {busy === 'regenerate' ? t('publish.regenerate_starting') : waiting ? t('publish.regenerating') : t('publish.regenerate')}
                    </button>
                </div>
            )}

            {groups.length === 0 && (
                <p className="text-[12.5px] text-text-secondary">{t('publish.nothing_to_fix')}</p>
            )}

            {groups.map(([key, label, items]) => (
                <section key={key} className="rounded-tile border border-border-default bg-bg-surface" aria-labelledby={`check-${key}`}>
                    <h3 id={`check-${key}`} className="border-b border-border-default px-3 py-2 text-[12.5px] font-semibold text-text-primary">
                        {label}
                    </h3>
                    <ul className="divide-y divide-border-default">
                        {items.map((item) => {
                            const [mark, tone, words] = ITEM[item.status];

                            return (
                                <li key={`${item.key}`} data-a={`publish-flow:item:${item.key}`} data-status={item.status} className="flex items-start gap-2 px-3 py-2">
                                    <span className={`w-3 shrink-0 text-[12.5px] font-bold ${tone}`} aria-hidden="true">{mark}</span>
                                    <p className="min-w-0 flex-1 text-[12.5px] leading-snug text-text-body">
                                        <span className="sr-only">{t(words)}: </span>
                                        {item.message}
                                    </p>
                                    {item.fix && item.status !== 'pass' && (
                                        <button
                                            type="button"
                                            data-a={`publish-flow:fix:${item.key}`}
                                            onClick={() => onFix(item.fix.pane)}
                                            className="shrink-0 rounded-control border border-border-input px-2 py-1 text-[11.5px] font-semibold text-brand-primary transition hover:border-brand-primary/40"
                                        >
                                            {t('publish.fix')}<span className="sr-only">: {item.fix.label}</span>
                                            <span className="max-sm:hidden"> · {item.fix.label}</span>
                                        </button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}

            <div className="flex flex-wrap items-center gap-3">
                <button type="button" onClick={() => setShowPassed((s) => !s)} className="text-[12px] font-medium text-text-secondary underline hover:text-text-primary">
                    {showPassed ? t('publish.hide_ready') : t('publish.show_ready', { count: check.counts.pass })}
                </button>
                <button
                    type="button"
                    data-a="publish-flow:next:channels"
                    onClick={onNext}
                    className="ml-auto inline-flex items-center gap-1 rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover"
                >
                    {t('publish.step_channels')} <Icon name="right" size={13} strokeWidth={2} />
                </button>
            </div>

            {check.files.length > 0 && (
                <section className="rounded-tile border border-border-default bg-bg-surface p-3" aria-label={t('publish.files')}>
                    <h3 className="mb-1.5 text-[12.5px] font-semibold text-text-primary">{t('publish.files')}</h3>
                    <ul className="space-y-1">
                        {check.files.map((file) => (
                            <li key={`${file.format}-${file.service ?? ''}`} className="flex flex-wrap items-center gap-x-2 text-[12px]">
                                <span className={file.fresh ? 'text-status-success' : 'text-status-warning'} aria-hidden="true">{file.fresh ? '✓' : '!'}</span>
                                <span className="text-text-primary">
                                    {file.label}
                                    {file.service ? ` (${file.service === 'kdp' ? 'KDP' : file.service === 'lulu' ? 'Lulu' : file.service})` : ''}
                                </span>
                                <span className="text-text-secondary">{file.fresh ? t('publish.file_up_to_date') : file.reason}</span>
                                {file.download && (
                                    <a href={file.download} className="font-semibold text-brand-primary hover:underline">
                                        {t('publish.download')}
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-1.5 text-[11px] text-text-secondary">
                        {t('publish.cover_not_detected')}
                    </p>
                </section>
            )}
        </div>
    );
}

// ── Step 2 ──────────────────────────────────────────────────────────────

const MODE = {
    automatic: ['publish.mode_automatic', 'bg-status-success-bg text-status-success'],
    guided: ['publish.mode_guided', 'bg-status-info-bg text-status-info'],
    spreadsheet: ['publish.mode_spreadsheet', 'bg-status-info-bg text-status-info'],
    not_connected: ['publish.mode_not_connected', 'bg-status-warning-bg text-status-warning'],
    coming_soon: ['publish.mode_coming_soon', 'bg-bg-subtle text-text-secondary'],
    off: ['publish.mode_off', 'bg-bg-subtle text-text-secondary'],
};

const STATUS_KEYS = {
    live: 'publish.status_live',
    in_review: 'publish.status_in_review',
    ready_to_submit: 'publish.status_ready_to_submit',
    not_submitted: 'publish.status_not_submitted',
    unpublished: 'publish.status_unpublished',
    draft: 'publish.status_draft',
    taken_down: 'publish.status_taken_down',
    validating: 'publish.status_validating',
    validated: 'publish.status_validated',
    failed: 'publish.status_failed',
    stale: 'publish.status_stale',
};

/** A channel or Lulu state in words; undefined for one without words. */
const statusLabel = (status) => (STATUS_KEYS[status] ? t(STATUS_KEYS[status]) : undefined);

function ChannelsStep({ data, busy, onChoose, onNext }) {
    return (
        <div className="space-y-3">
            <p className="text-[12.5px] text-text-secondary">{t('publish.channels_intro')}</p>
            <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
                {data.cards.map((card) => {
                    const [modeKey, tone] = MODE[card.mode];
                    const mode = t(modeKey);

                    return (
                        <li
                            key={card.key}
                            data-a={`publish-flow:card:${card.key}`}
                            className={`rounded-tile border bg-bg-surface p-3 ${card.chosen ? 'border-brand-primary' : 'border-border-default'} ${card.selectable ? '' : 'opacity-75'}`}
                        >
                            <div className="flex items-start gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-[13px] font-semibold text-text-primary">{card.label}</p>
                                    <p className="mt-1 flex flex-wrap gap-1.5">
                                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone}`}>{mode}</span>
                                        <span className="rounded-full bg-bg-subtle px-2 py-0.5 text-[11px] text-text-secondary">{card.format === 'print' ? t('publish.format_print') : t('publish.format_ebook')}</span>
                                        {card.status !== 'not_started' && card.status !== 'not_connected' && STATUS_KEYS[card.status] && (
                                            <span data-a={`publish-flow:card-status:${card.key}`} className="rounded-full bg-bg-subtle px-2 py-0.5 text-[11px] font-medium text-text-body">
                                                {statusLabel(card.status)}
                                                {card.external_id ? ` · ${card.external_id}` : ''}
                                            </span>
                                        )}
                                    </p>
                                </div>
                                {card.selectable && (
                                    <button
                                        type="button"
                                        role="switch"
                                        aria-checked={card.chosen}
                                        aria-label={card.label}
                                        data-a={`publish-flow:choose:${card.key}`}
                                        disabled={busy === `card:${card.key}` || !data.can.update}
                                        onClick={() => onChoose(card.key, !card.chosen)}
                                        className={`h-[22px] w-[38px] shrink-0 rounded-full p-[2px] transition disabled:opacity-50 ${card.chosen ? 'bg-brand-fill' : 'bg-border-input'}`}
                                    >
                                        <span className={`block h-[18px] w-[18px] rounded-full bg-white transition-transform ${card.chosen ? 'translate-x-4' : ''}`} />
                                    </button>
                                )}
                            </div>
                            <p className="mt-2 text-[12px] leading-snug text-text-body">{card.what}</p>
                        </li>
                    );
                })}
            </ul>
            <div className="flex justify-end">
                <button
                    type="button"
                    data-a="publish-flow:next:publish"
                    onClick={onNext}
                    className="inline-flex items-center gap-1 rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover"
                >
                    {t('publish.step_publish')} <Icon name="right" size={13} strokeWidth={2} />
                </button>
            </div>
        </div>
    );
}

// ── Step 3 ──────────────────────────────────────────────────────────────

function PublishStep({ bookId, data, busy, act, onFix, onBack }) {
    const chosen = data.cards.filter((card) => card.chosen);
    const red = data.check.status === 'red';

    if (chosen.length === 0) {
        return (
            <div className="space-y-3">
                <p className="text-[12.5px] text-text-secondary">{t('publish.no_channel')}</p>
                <button type="button" onClick={onBack} className="rounded-control border border-border-input px-3 py-2 text-[12.5px] font-semibold text-text-body">
                    {t('publish.step_channels')}
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {red && (
                <p role="status" className="rounded-tile bg-status-danger-bg p-3 text-[12.5px] text-status-danger">
                    {t('publish.step1_red')}
                </p>
            )}
            {chosen.map((card) => (
                <section key={card.key} className="rounded-tile border border-border-default bg-bg-surface p-3" data-a={`publish-flow:do:${card.key}`}>
                    <h3 className="mb-2 text-[13.5px] font-semibold text-text-primary">{card.label}</h3>
                    {card.key === 'store' && <StorePanel bookId={bookId} data={data} busy={busy} act={act} />}
                    {card.key === 'lulu' && <LuluPanel bookId={bookId} data={data} busy={busy} act={act} />}
                    {(card.key === 'kdp_ebook' || card.key === 'kdp_paperback') && (
                        <KdpPanel bookId={bookId} card={card} guide={data.kdp[card.key === 'kdp_ebook' ? 'ebook' : 'paperback']} data={data} busy={busy} act={act} onFix={onFix} />
                    )}
                    {card.key === 'google_play' && <GooglePanel bookId={bookId} card={card} data={data} busy={busy} act={act} />}
                </section>
            ))}
        </div>
    );
}

const primary = 'rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50';
const secondary = 'rounded-control border border-border-input px-3 py-2 text-[12.5px] font-semibold text-text-body transition hover:border-brand-primary/40 disabled:opacity-50';

function StorePanel({ bookId, data, busy, act }) {
    const store = data.store;

    if (store?.state === 'live') {
        return (
            <p className="text-[12.5px] text-text-body">
                <span className="font-semibold text-status-success">{t('publish.store_on_sale')}</span>{' '}
                <a href={store.url} target="_blank" rel="noopener noreferrer" className="font-semibold text-brand-primary hover:underline">
                    {t('publish.store_see_page')}
                </a>
                {t('publish.store_change')}
            </p>
        );
    }

    return (
        <div className="space-y-2">
            <p className="text-[12.5px] text-text-body">
                {store ? t('publish.store_draft') : t('publish.store_new')}
            </p>
            {data.can.manage ? (
                <button
                    type="button"
                    data-a="publish-flow:store"
                    disabled={busy === 'store'}
                    onClick={() => act('store', () => window.axios.post(`/books/${bookId}/publish-flow/store`))}
                    className={primary}
                >
                    {busy === 'store' ? t('publish.putting_on_sale') : t('publish.put_on_sale')}
                </button>
            ) : (
                <p className="text-[12px] text-text-secondary">{t('publish.owner_only')}</p>
            )}
        </div>
    );
}

function LuluPanel({ bookId, data, busy, act }) {
    const lulu = data.lulu;

    if (!lulu.connected) {
        return (
            <div data-a="publish-flow:lulu:connect" className="space-y-1.5">
                <p className="text-[12.5px] font-semibold text-text-primary">{t('publish.lulu_connect')}</p>
                <p className="text-[12.5px] text-text-body">
                    {t('publish.lulu_connect_help')}
                </p>
                <ul className="list-disc space-y-0.5 pl-5 text-[12px] text-text-body">
                    {lulu.needs.map((need) => (
                        <li key={need}>{need}</li>
                    ))}
                </ul>
            </div>
        );
    }

    const errors = [...(lulu.interior?.errors ?? []), ...(lulu.cover?.errors ?? [])];

    return (
        <div className="space-y-2 text-[12.5px]">
            <p className="text-text-body">
                {lulu.package ? <>{t('publish.lulu_product')} <span className="font-semibold">{lulu.package}</span> ({lulu.pod_package_id}).</> : lulu.package_problem}
                {lulu.environment === 'sandbox' && <span className="ml-1 text-text-secondary">{t('publish.lulu_sandbox')}</span>}
            </p>
            <p data-a="publish-flow:lulu:state" className="font-semibold text-text-primary">{statusLabel(lulu.state) ?? t('publish.lulu_not_sent')}</p>
            {lulu.unit_cost_cents != null && (
                <p className="text-text-secondary">{t('publish.lulu_quote', { price: formatMoney(lulu.unit_cost_cents, 'USD') })}</p>
            )}
            {errors.length > 0 && (
                <ul className="list-disc pl-5 text-status-danger">
                    {errors.map((e) => (
                        <li key={e}>{e}</li>
                    ))}
                </ul>
            )}
            {!lulu.shipping_set && <p className="text-status-warning">{t('publish.lulu_no_shipping')}</p>}
            <div className="flex flex-wrap gap-2">
                {data.can.manage && ['not_started', 'failed', 'stale', 'validated'].includes(lulu.state) && (
                    <button type="button" data-a="publish-flow:lulu:send" disabled={busy === 'lulu'} onClick={() => act('lulu', () => window.axios.post(`/books/${bookId}/publish-flow/lulu`))} className={lulu.state === 'validated' ? secondary : primary}>
                        {busy === 'lulu' ? t('publish.sending') : lulu.state === 'not_started' ? t('publish.lulu_send') : t('publish.lulu_send_again')}
                    </button>
                )}
                {lulu.state === 'validating' && (
                    <button type="button" data-a="publish-flow:lulu:refresh" disabled={busy === 'lulu-refresh'} onClick={() => act('lulu-refresh', () => window.axios.post(`/books/${bookId}/publish-flow/lulu/refresh`))} className={secondary}>
                        {t('publish.check_again')}
                    </button>
                )}
                {data.can.manage && (lulu.state === 'validated' || lulu.state === 'live') && (
                    <button
                        type="button"
                        data-a="publish-flow:lulu:offer"
                        disabled={busy === 'lulu-offer'}
                        onClick={() => act('lulu-offer', () => window.axios.post(`/books/${bookId}/publish-flow/lulu/offer`, { on: lulu.state !== 'live' }))}
                        className={lulu.state === 'live' ? secondary : primary}
                    >
                        {lulu.state === 'live' ? t('publish.lulu_stop') : t('publish.lulu_sell')}
                    </button>
                )}
            </div>
        </div>
    );
}

function KdpPanel({ bookId, card, guide, data, busy, act, onFix }) {
    const [editing, setEditing] = useState(null);

    if (!guide) return null;

    const save = (changes) => act(`kdp:${card.key}`, () => window.axios.patch(`/books/${bookId}/publish-flow/kdp`, changes), t('publish.saved')).then((ok) => ok && setEditing(null));

    const editor = (field, id) =>
        editing === field.key ? (
            <KdpEditor field={field} id={id} busy={busy === `kdp:${card.key}`} onSave={save} onCancel={() => setEditing(null)} limits={guide.limits} />
        ) : (
            data.can.update && (
                <button type="button" data-a={`kdp:edit:${field.key}`} onClick={() => setEditing(field.key)} className="mt-1 text-[11.5px] font-semibold text-brand-primary hover:underline">
                    {t('publish.edit')}
                </button>
            )
        );

    return (
        <div className="space-y-3">
            <p className="text-[12.5px] text-text-body">
                {t('publish.kdp_intro', { kind: guide.label })}
            </p>
            <div className="flex flex-wrap gap-2">
                <a href={guide.url} target="_blank" rel="noopener noreferrer" data-a={`publish-flow:open-kdp:${card.key}`} className={primary}>
                    {t('publish.open_kdp')}
                </a>
                <a href={`/publish/kdp-checklist?books[]=${bookId}`} target="_blank" rel="noopener noreferrer" className={secondary}>
                    {t('publish.printable_checklist')}
                </a>
            </div>
            <KdpGuideView guide={guide} onFix={onFix} editor={editor} />
            <Submitted bookId={bookId} card={card} busy={busy} act={act} asin canUpdate={data.can.update} />
        </div>
    );
}

function KdpEditor({ field, id, busy, onSave, onCancel, limits }) {
    const initial = field.key === 'audience' ? field.audience : field.value ?? [];
    const [value, setValue] = useState(initial);

    if (field.key === 'audience') {
        return (
            <fieldset className="mt-2 space-y-2 rounded-tile bg-bg-subtle p-2.5" data-a="kdp:audience-editor">
                <legend className="sr-only">{t('publish.kdp_audience')}</legend>
                <p className="text-[12px] text-text-body">{t('publish.kdp_explicit')}</p>
                <div className="flex gap-3 text-[12.5px]">
                    {[
                        [false, t('publish.no')],
                        [true, t('publish.yes')],
                    ].map(([on, label]) => (
                        <label key={label} className="flex items-center gap-1.5">
                            <input type="radio" name={`${id}-explicit`} checked={value.explicit === on} onChange={() => setValue({ ...value, explicit: on })} />
                            {label}
                        </label>
                    ))}
                </div>
                <div className="flex flex-wrap items-center gap-2 text-[12px]">
                    <label htmlFor={`${id}-min`}>{t('publish.kdp_age_from')}</label>
                    <input id={`${id}-min`} type="number" min="0" max="18" value={value.min_age ?? ''} onChange={(e) => setValue({ ...value, min_age: e.target.value === '' ? null : Number(e.target.value) })} className="w-16 rounded-control border border-border-input px-1.5 py-1" />
                    <label htmlFor={`${id}-max`}>{t('publish.kdp_age_to')}</label>
                    <input id={`${id}-max`} type="number" min="0" max="18" value={value.max_age ?? ''} onChange={(e) => setValue({ ...value, max_age: e.target.value === '' ? null : Number(e.target.value) })} className="w-16 rounded-control border border-border-input px-1.5 py-1" />
                    <span className="text-text-secondary">{t('publish.optional')}</span>
                </div>
                <EditorButtons busy={busy} onCancel={onCancel} onSave={() => onSave(value)} />
            </fieldset>
        );
    }

    const keywords = field.key === 'keywords';
    const count = keywords ? limits.keywords : limits.categories;
    const max = keywords ? limits.keyword_chars : 200;
    const rows = Array.from({ length: count }, (_, i) => value[i] ?? '');

    return (
        <div className="mt-2 space-y-1.5 rounded-tile bg-bg-subtle p-2.5" data-a={`kdp:${field.key}-editor`}>
            {rows.map((row, i) => (
                <div key={i} className="flex items-center gap-2">
                    <label htmlFor={`${id}-${i}`} className="w-5 text-right text-[11px] tabular-nums text-text-secondary">
                        <span className="sr-only">{keywords ? t('publish.keyword') : t('publish.category')} </span>
                        {i + 1}
                    </label>
                    <input
                        id={`${id}-${i}`}
                        data-a={`kdp:${field.key}:${i}`}
                        value={row}
                        maxLength={max}
                        onChange={(e) => {
                            const next = [...rows];
                            next[i] = e.target.value;
                            setValue(next);
                        }}
                        className="min-w-0 flex-1 rounded-control border border-border-input bg-bg-surface px-2 py-1 text-[12.5px]"
                    />
                    {keywords && <span className={`w-10 text-right text-[10.5px] tabular-nums ${row.length > max - 5 ? 'text-status-warning' : 'text-text-secondary'}`}>{row.length}/{max}</span>}
                </div>
            ))}
            <EditorButtons busy={busy} onCancel={onCancel} onSave={() => onSave({ [field.key]: rows.filter((r) => r.trim() !== '') })} />
        </div>
    );
}

function EditorButtons({ busy, onSave, onCancel }) {
    return (
        <div className="flex gap-2 pt-1">
            <button type="button" data-a="kdp:save" disabled={busy} onClick={onSave} className="rounded-control bg-brand-fill px-2.5 py-1 text-[12px] font-semibold text-white disabled:opacity-50">
                {busy ? t('publish.saving') : t('publish.save')}
            </button>
            <button type="button" onClick={onCancel} className="rounded-control border border-border-input px-2.5 py-1 text-[12px] font-medium text-text-body">
                {t('common.cancel')}
            </button>
        </div>
    );
}

function GooglePanel({ bookId, card, data, busy, act }) {
    return (
        <div className="space-y-2 text-[12.5px]">
            <p className="text-text-body">
                {t('publish.google_intro')}
            </p>
            {data.google.length > 0 && (
                <ul className="list-disc pl-5 text-status-warning">
                    {data.google.map((skip) => (
                        <li key={skip.id}>{skip.reason}</li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap gap-2">
                <a href={`/books/${bookId}/publish-flow/google-play`} data-a="publish-flow:google-play" className={primary}>
                    {t('publish.google_download')}
                </a>
                <a href="https://play.google.com/books/publish/" target="_blank" rel="noopener noreferrer" className={secondary}>
                    {t('publish.google_open')}
                </a>
            </div>
            <p className="text-[11.5px] text-text-secondary">
                {t('publish.google_columns')}
            </p>
            <Submitted bookId={bookId} card={card} busy={busy} act={act} canUpdate={data.can.update} />
        </div>
    );
}

function Submitted({ bookId, card, busy, act, asin = false, canUpdate }) {
    const today = new Date().toISOString().slice(0, 10);
    const [form, setForm] = useState({ external_id: card.external_id ?? '', date: card.submitted_at ?? today, state: card.status === 'live' ? 'live' : 'in_review' });
    const done = card.status === 'in_review' || card.status === 'live';

    if (!canUpdate) return null;

    const submit = (event) => {
        event.preventDefault();
        act(`submitted:${card.key}`, () => window.axios.post(`/books/${bookId}/publish-flow/submitted`, { card: card.key, ...form, external_id: form.external_id || null }));
    };

    return (
        <form onSubmit={submit} className="space-y-2 rounded-tile border border-border-default p-2.5" data-a={`publish-flow:submitted:${card.key}`}>
            <p className="text-[12.5px] font-semibold text-text-primary">
                {done ? `${t('publish.recorded', { status: statusLabel(card.status) })}${card.external_id ? ` · ${card.external_id}` : ''}` : t('publish.submitted_it')}
            </p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <label className="block text-[11.5px] text-text-secondary">
                    {asin ? t('publish.asin_label') : t('publish.google_id_label')}
                    <input
                        data-a={`publish-flow:asin:${card.key}`}
                        required={asin}
                        value={form.external_id}
                        maxLength={40}
                        placeholder={asin ? 'B0…' : ''}
                        onChange={(e) => setForm({ ...form, external_id: e.target.value.toUpperCase() })}
                        className="mt-0.5 w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] uppercase text-text-primary"
                    />
                </label>
                <label className="block text-[11.5px] text-text-secondary">
                    {t('publish.date')}
                    <input
                        type="date"
                        data-a={`publish-flow:date:${card.key}`}
                        required
                        max={today}
                        value={form.date}
                        onChange={(e) => setForm({ ...form, date: e.target.value })}
                        className="mt-0.5 w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] text-text-primary"
                    />
                </label>
                <label className="block text-[11.5px] text-text-secondary">
                    {t('publish.where_stands')}
                    <select
                        data-a={`publish-flow:state:${card.key}`}
                        value={form.state}
                        onChange={(e) => setForm({ ...form, state: e.target.value })}
                        className="mt-0.5 w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] text-text-primary"
                    >
                        <option value="in_review">{t('publish.submitted_in_review')}</option>
                        <option value="live">{t('publish.status_live')}</option>
                    </select>
                </label>
            </div>
            <button type="submit" data-a={`publish-flow:mark:${card.key}`} disabled={busy === `submitted:${card.key}`} className={done ? secondary : primary}>
                {busy === `submitted:${card.key}` ? t('publish.saving') : done ? t('publish.update') : t('publish.mark_submitted')}
            </button>
        </form>
    );
}
