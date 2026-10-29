import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ShortcutHost } from '../ShortcutSheet';
import { seedPrefs } from '../../shortcuts';
import { useDocumentLanguage, useI18n } from '../../i18n';

/**
 * The platform layer around every page: the "View as" banner, the
 * announcement, staff's maintenance reminder, and one-off notices.
 *
 * Set as every page's persistent layout in app.jsx, so no screen can forget
 * it — the editor, the shelf, the store and the console alike. Pages draw
 * themselves as a full-height column (`h-dvh`) that ends in the phone tab bar
 * (ADR-0024); with a banner showing, the page sits in the space below it
 * (app.css: `.platform-frame-body .h-dvh`), so the banner pushes the page
 * down instead of covering its header, and the tab bar stays on screen.
 *
 * The wrapper elements are always rendered (as `display: contents` when
 * there is nothing to show), so a banner appearing or going away never
 * remounts the page under it — an open editor keeps its state.
 */
export default function PlatformFrame({ children }) {
    const { platform, prefs } = usePage().props;
    // The author's shortcuts, tabs and swipes, before anything below reads
    // them (resources/js/shortcuts.js).
    seedPrefs(prefs ?? null);
    // The interface language, likewise first: t() outside components reads
    // what this sets (resources/js/i18n.js), and <html lang> follows it.
    useI18n();
    useDocumentLanguage();
    const impersonation = platform?.impersonation ?? null;
    const announcement = platform?.announcement ?? null;
    const maintenance = Boolean(platform?.maintenance);

    const [dismissed, setDismissed] = useState(() => readDismissed());
    const showAnnouncement = announcement && dismissed !== announcement.id;
    const framed = Boolean(impersonation || showAnnouncement || maintenance);

    return (
        <div className={framed ? 'platform-frame flex h-dvh flex-col' : 'contents'}>
            <div className={framed ? 'shrink-0' : 'contents'}>
                {impersonation && <ImpersonationBanner session={impersonation} />}
                {maintenance && !impersonation && (
                    <p
                        role="status"
                        data-a="platform:maintenance-on"
                        className="bg-status-warning-fill px-4 py-1.5 text-center text-[12.5px] font-semibold text-white"
                    >
                        Maintenance mode is on: only Ecrivain staff can use the app.
                    </p>
                )}
                {showAnnouncement && (
                    <AnnouncementBanner
                        announcement={announcement}
                        onDismiss={() => {
                            writeDismissed(announcement.id);
                            setDismissed(announcement.id);
                        }}
                    />
                )}
            </div>
            <div className={framed ? 'platform-frame-body relative min-h-0 flex-1 overflow-auto' : 'contents'}>
                {children}
            </div>
            <Notice />
            <ShortcutHost />
        </div>
    );
}

/**
 * "Viewing as …", with the time left and Stop viewing. Red-brown and on
 * every page, so nobody forgets whose account they are in.
 */
function ImpersonationBanner({ session }) {
    const left = useCountdown(session.expires_at);
    const [stopping, setStopping] = useState(false);

    // The server ends the session at the limit; reloading lets it do so and
    // put the admin back, rather than leaving a page that will refuse
    // everything it is asked.
    useEffect(() => {
        if (left === 0) router.reload();
    }, [left === 0]);

    const minutes = Math.floor((left ?? 0) / 60);
    const seconds = String((left ?? 0) % 60).padStart(2, '0');

    return (
        <div
            role="region"
            aria-label="Viewing as another account"
            data-a="platform:impersonation-banner"
            className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-status-danger-fill px-4 py-2 text-[13px] text-white"
        >
            <span className="min-w-0 truncate">
                <span className="font-semibold">Viewing as {session.target?.name}</span>
                <span className="opacity-90 max-sm:hidden"> ({session.target?.email})</span>
            </span>
            <span className="tabular-nums opacity-95" data-a="platform:impersonation-time" aria-live="off">
                {minutes}:{seconds} left
            </span>
            <button
                type="button"
                data-a="platform:stop-viewing"
                disabled={stopping}
                onClick={() => {
                    setStopping(true);
                    router.post(route('impersonation.stop'), {}, { onFinish: () => setStopping(false) });
                }}
                className="tap rounded-button bg-white px-3 py-1 text-[12.5px] font-semibold text-status-danger transition hover:bg-white/90 disabled:opacity-60"
            >
                {stopping ? 'Stopping…' : 'Stop viewing'}
            </button>
        </div>
    );
}

const TONES = {
    info: 'bg-status-info-bg text-status-info',
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    danger: 'bg-status-danger-bg text-status-danger',
};

function AnnouncementBanner({ announcement, onDismiss }) {
    return (
        <div
            role="status"
            data-a="platform:announcement"
            className={`flex items-start justify-center gap-3 border-b border-border-divider px-4 py-2 text-[13px] ${
                TONES[announcement.tone] ?? TONES.info
            }`}
        >
            <p className="min-w-0 flex-1 text-center font-medium">
                {announcement.message}
                {/* An incident's banner links to the status page. */}
                {announcement.url && (
                    <>
                        {' '}
                        <a href={announcement.url} data-a="platform:announcement-link" className="underline underline-offset-2">
                            Status page
                        </a>
                    </>
                )}
            </p>
            <button
                type="button"
                data-a="platform:announcement-dismiss"
                onClick={onDismiss}
                aria-label="Dismiss announcement"
                className="tap -my-1 shrink-0 rounded-control px-2 text-[16px] leading-none opacity-70 hover:opacity-100"
            >
                ×
            </button>
        </div>
    );
}

/**
 * A one-off message from a redirect ("Stopped viewing", a console action's
 * result). Above the phone tab bar, gone after eight seconds.
 */
function Notice() {
    const { flash } = usePage().props;
    const { t } = useI18n();
    const text = flash?.notice ?? flash?.error ?? null;
    const [shown, setShown] = useState(text);

    useEffect(() => {
        setShown(text);
        if (!text) return undefined;

        const timer = setTimeout(() => setShown(null), 8000);

        return () => clearTimeout(timer);
    }, [text]);

    if (!shown) return null;

    return (
        <div
            role="status"
            data-a="platform:notice"
            className="fixed bottom-4 left-1/2 z-[60] w-[min(92vw,520px)] -translate-x-1/2 rounded-panel bg-bg-inverse px-4 py-3 text-[13px] text-text-inverse shadow-floating max-lg:bottom-[calc(76px+env(safe-area-inset-bottom))]"
        >
            <div className="flex items-start gap-3">
                <p className="flex-1">{shown}</p>
                <button
                    type="button"
                    onClick={() => setShown(null)}
                    aria-label={t('common.dismiss')}
                    className="shrink-0 text-[16px] leading-none opacity-70 hover:opacity-100"
                >
                    ×
                </button>
            </div>
        </div>
    );
}

/** Seconds until `iso`, ticking once a second; 0 once it has passed. */
function useCountdown(iso) {
    const target = iso ? new Date(iso).getTime() : null;
    const compute = () => (target ? Math.max(0, Math.round((target - Date.now()) / 1000)) : null);
    const [left, setLeft] = useState(compute);

    useEffect(() => {
        setLeft(compute());
        const timer = setInterval(() => setLeft(compute()), 1000);

        return () => clearInterval(timer);
    }, [target]);

    return left;
}

// A dismissed announcement stays dismissed in this browser until its text
// changes. Storage can be unavailable (private windows); then it just shows.
const KEY = 'ecrivain.announcement.dismissed';

function readDismissed() {
    try {
        return window.localStorage.getItem(KEY);
    } catch {
        return null;
    }
}

function writeDismissed(id) {
    try {
        window.localStorage.setItem(KEY, id);
    } catch {
        // Not remembered; dismissed for this page only.
    }
}
