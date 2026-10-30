import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { useI18n } from '../../i18n';
import { isTyping, matches, savePrefs, shortcutLabel, usePrefs } from '../../shortcuts';

/**
 * Findings one at a time: the card, the passage, Apply / Dismiss / Next.
 *
 * A list of thirty findings on a phone is thirty cards of scrolling with the
 * text out of sight. Writing tools that review well on a small screen show
 * one suggestion, say how far through you are, and move on when you decide.
 *
 * Nothing changes the manuscript without the author's tap. A swipe is only a
 * shortcut for the buttons beside it (right applies a fix, left dismisses),
 * which an author can swap (left-handed) or turn off (buttons only), saved
 * on the account (ADR — Configurable shortcuts and mobile tabs); and every
 * decision can be undone for a few seconds afterwards — through
 * the same server undo the AI Editor screen uses, so an applied fix really
 * comes back out of the chapter.
 */
const SWIPE = 90;
const UNDO_MS = 6000;

export default function ReviewDeck({ findings, agents = [], onResolve, onReopen, onShowInText, onClose }) {
    const { t } = useI18n();
    const open = findings.filter((f) => f.status === 'open');
    const [index, setIndex] = useState(0);
    const [last, setLast] = useState(null); // {finding, action}
    const [busy, setBusy] = useState(false);
    const [dx, setDx] = useState(0);
    const start = useRef(null);
    const wanted = useRef(null);
    const prefs = usePrefs();
    const swipe = prefs.reviewDeck.swipe;
    // +1: a swipe right applies. Swapped, a swipe left does.
    const applySide = swipe === 'swapped' ? -1 : 1;

    // Out of range once the last card is decided; an undone finding comes
    // back wherever the server lists it, so the deck goes to it.
    useEffect(() => {
        if (wanted.current) {
            const at = open.findIndex((f) => f.id === wanted.current);

            if (at >= 0) {
                setIndex(at);
                wanted.current = null;

                return;
            }
        }

        if (index > 0 && index >= open.length) setIndex(Math.max(0, open.length - 1));
    }, [open.length]);

    useEffect(() => {
        if (!last) return undefined;

        const timer = setTimeout(() => setLast(null), UNDO_MS);

        return () => clearTimeout(timer);
    }, [last]);

    const finding = open[index] ?? null;
    const agent = finding && agents.find((a) => a.value === finding.agent);

    const decide = async (action) => {
        if (!finding || busy) return;
        if (action === 'accepted' && !finding.suggestion) return;

        setBusy(true);
        setDx(0);

        try {
            const done = await onResolve(finding, action);

            if (done !== false) {
                setLast({ finding, action });
                navigator.vibrate?.(10);
            }
        } finally {
            setBusy(false);
        }
    };

    const undo = async () => {
        if (!last || busy) return;

        setBusy(true);
        wanted.current = last.finding.id;

        try {
            await onReopen(last.finding);
        } finally {
            setLast(null);
            setBusy(false);
        }
    };

    const step = (by) => {
        if (open.length === 0) return;
        setIndex((i) => (i + by + open.length) % open.length);
    };

    // Swipe, horizontal only: a vertical drag is the card scrolling.
    const onTouchStart = (event) => {
        if (swipe === 'off') return;

        const touch = event.touches[0];
        start.current = { x: touch.clientX, y: touch.clientY, horizontal: null };
    };
    const onTouchMove = (event) => {
        if (!start.current) return;

        const touch = event.touches[0];
        const x = touch.clientX - start.current.x;
        const y = touch.clientY - start.current.y;

        if (start.current.horizontal === null && (Math.abs(x) > 8 || Math.abs(y) > 8)) {
            start.current.horizontal = Math.abs(x) > Math.abs(y);
        }

        if (start.current.horizontal) setDx(x);
    };
    const onTouchEnd = () => {
        const moved = dx;
        start.current = null;

        if (moved * applySide > SWIPE && finding?.suggestion) decide('accepted');
        else if (moved * applySide < -SWIPE) decide('dismissed');
        else setDx(0);
    };

    // Arrow keys and A / D / N, for a tablet with a keyboard: the deck.*
    // shortcuts of the registry, so the author's own keys if they moved them.
    useEffect(() => {
        const onKey = (event) => {
            if (isTyping(event) || document.querySelector('[aria-modal="true"]')) return;
            if (matches('deck.next', event)) step(1);
            else if (matches('deck.previous', event)) step(-1);
            else if (matches('deck.apply', event)) decide('accepted');
            else if (matches('deck.dismiss', event)) decide('dismissed');
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    });

    return (
        <div className="flex min-h-full flex-col p-3.5" data-a="review-deck">
            <div className="mb-3 flex items-center gap-2">
                <button
                    data-a="review:close"
                    onClick={onClose}
                    className="tap -ml-1 flex items-center gap-1 rounded-control px-1 text-[12px] font-medium text-brand-primary hover:underline"
                >
                    <Icon name="left" size={14} /> {t('ai.all_agents')}
                </button>
                <label className="ml-auto flex items-center gap-1 text-[11.5px] text-text-secondary">
                    {t('review.swipes')}
                    <select
                        data-a="review:swipe"
                        value={swipe}
                        onChange={(event) => savePrefs({ reviewDeck: { swipe: event.target.value } }).catch(() => {})}
                        className="rounded-control border border-border-default bg-bg-surface py-0.5 pl-1.5 pr-6 text-[11.5px] text-text-body"
                    >
                        <option value="normal">{t('review.swipe_right')}</option>
                        <option value="swapped">{t('review.swipe_left')}</option>
                        <option value="off">{t('review.swipe_off')}</option>
                    </select>
                </label>
                <span className="text-[12px] tabular-nums text-text-secondary" aria-live="polite">
                    {open.length === 0 ? t('review.all_done') : t('review.position', { n: index + 1, total: open.length })}
                </span>
            </div>

            {open.length > 0 && (
                <div className="mb-3 h-1 overflow-hidden rounded-full bg-bg-subtle" aria-hidden="true">
                    <div
                        className="h-full rounded-full bg-brand-fill transition-all"
                        style={{ width: `${((index + 1) / open.length) * 100}%` }}
                    />
                </div>
            )}

            {!finding ? (
                <div className="grid flex-1 place-items-center py-10 text-center">
                    <div>
                        <p className="text-[15px] font-semibold text-text-primary">{t('review.nothing_left')}</p>
                        <p className="mt-1 text-[12.5px] text-text-secondary">
                            {t('review.all_decided')}
                        </p>
                    </div>
                </div>
            ) : (
                <article
                    key={finding.id}
                    data-a={`review:card:${finding.id}`}
                    onTouchStart={onTouchStart}
                    onTouchMove={onTouchMove}
                    onTouchEnd={onTouchEnd}
                    style={{
                        transform: dx ? `translateX(${dx}px) rotate(${dx / 40}deg)` : undefined,
                        transition: dx ? 'none' : 'transform 160ms ease-out',
                    }}
                    className={`relative rounded-panel border bg-bg-surface p-4 shadow-sm ${
                        dx * applySide > SWIPE && finding.suggestion
                            ? 'border-status-success'
                            : dx * applySide < -SWIPE
                              ? 'border-status-danger'
                              : 'border-border-default'
                    }`}
                >
                    <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.06em] text-text-secondary">
                        <span
                            className={`h-2 w-2 shrink-0 rounded-full ${
                                finding.severity === 'critical'
                                    ? 'bg-status-danger'
                                    : finding.severity === 'warning'
                                      ? 'bg-status-warning'
                                      : 'bg-status-info'
                            }`}
                        />
                        {agent?.label ?? finding.agent}
                        {finding.simulated && (
                            <span className="ml-auto rounded-badge bg-bg-subtle px-1.5 py-0.5 text-[9.5px]">{t('ai.sample')}</span>
                        )}
                    </p>

                    <h3 className="mt-2 text-[15.5px] font-semibold leading-snug text-text-primary">
                        {finding.title}
                    </h3>

                    {finding.detail && (
                        <p className="mt-1.5 text-[13px] leading-relaxed text-text-body">{finding.detail}</p>
                    )}

                    {finding.excerpt && (
                        <div className="mt-3 rounded-tile bg-bg-subtle p-3 text-[13px] leading-relaxed">
                            <p className={finding.suggestion ? 'text-text-secondary line-through decoration-status-danger/60' : 'italic text-text-body'}>
                                {finding.excerpt}
                            </p>
                            {finding.suggestion && (
                                <p className="mt-2 border-t border-border-divider pt-2 text-text-primary">
                                    <span className="sr-only">{t('review.suggested')} </span>
                                    {finding.suggestion}
                                </p>
                            )}
                        </div>
                    )}

                    {finding.stale && (
                        <p className="mt-2 rounded bg-status-warning-bg px-2 py-1 text-[11px] font-semibold text-status-warning">
                            {t('ai.stale')}
                        </p>
                    )}

                    {finding.excerpt && onShowInText && (
                        <button
                            data-a={`review:show:${finding.id}`}
                            onClick={() => onShowInText(finding)}
                            className="mt-2 text-[12px] font-semibold text-brand-primary hover:underline"
                        >
                            {t('ai.show_in_text')}
                        </button>
                    )}

                    {swipe !== 'off' && (
                        <p className="mt-3 text-[11px] text-text-secondary pointer-fine:hidden" data-a="review:swipe-hint">
                            {finding.suggestion
                                ? applySide > 0
                                    ? t('review.swipe_hint_right_apply')
                                    : t('review.swipe_hint_left_apply')
                                : applySide > 0
                                  ? t('review.swipe_hint_left_dismiss')
                                  : t('review.swipe_hint_right_dismiss')}
                        </p>
                    )}
                    <p className="mt-3 text-[11px] text-text-secondary pointer-coarse:hidden">
                        {t('review.keys_hint', {
                            apply: shortcutLabel('deck.apply'),
                            dismiss: shortcutLabel('deck.dismiss'),
                            previous: shortcutLabel('deck.previous'),
                            next: shortcutLabel('deck.next'),
                        })}
                    </p>
                </article>
            )}

            {finding && (
                <div className="mt-3 grid grid-cols-[auto_1fr_1fr_auto] gap-2">
                    <button
                        data-a="review:prev"
                        onClick={() => step(-1)}
                        aria-label={t('review.previous')}
                        disabled={open.length < 2}
                        className="grid h-11 w-11 place-items-center rounded-control border border-border-default text-text-secondary disabled:opacity-40"
                    >
                        <Icon name="left" size={16} />
                    </button>
                    <button
                        data-a="review:dismiss"
                        onClick={() => decide('dismissed')}
                        disabled={busy}
                        className="h-11 rounded-control border border-border-default text-[13.5px] font-semibold text-text-primary disabled:opacity-50"
                    >
                        {t('ai.dismiss')}
                    </button>
                    <button
                        data-a="review:apply"
                        onClick={() => decide('accepted')}
                        disabled={busy || !finding.suggestion}
                        title={finding.suggestion ? undefined : t('review.no_wording')}
                        className="h-11 rounded-control bg-status-success-fill text-[13.5px] font-semibold text-white disabled:bg-bg-subtle disabled:text-text-secondary"
                    >
                        {t('ai.apply_fix')}
                    </button>
                    <button
                        data-a="review:next"
                        onClick={() => step(1)}
                        aria-label={t('review.next')}
                        disabled={open.length < 2}
                        className="grid h-11 w-11 place-items-center rounded-control border border-border-default text-text-secondary disabled:opacity-40"
                    >
                        <Icon name="right" size={16} />
                    </button>
                </div>
            )}

            {last && (
                <div
                    role="status"
                    data-a="review:undo-bar"
                    className="sticky bottom-0 mt-3 flex items-center gap-3 rounded-control bg-bg-sidebar px-3.5 py-2.5 text-[13px] text-white shadow-popover"
                >
                    <span className="flex-1">{last.action === 'accepted' ? t('review.fix_applied') : t('review.dismissed')}</span>
                    <button
                        data-a="review:undo"
                        onClick={undo}
                        disabled={busy}
                        className="tap font-semibold text-brand-primary-subtle underline-offset-2 hover:underline"
                    >
                        {t('ai.undo')}
                    </button>
                </div>
            )}
        </div>
    );
}
