import Dialog from '../Dialog';
import { useI18n } from '@/i18n';

/** Why a version was saved, in words (ADR — Journey friction fixes). */
const REASONS = ['autosave', 'manual', 'import', 'restore', 'ai_suggestion', 'ai_draft', 'typography', 'review'];
// A check's fix (not AI) and a manuscript-wide replace (ADR — Author walkthrough fixes).
const MORE_REASONS = ['check_fix', 'replace'];

export function versionReason(t, reason) {
    if (MORE_REASONS.includes(reason)) {
        return t(`fric.version_reason_${reason}`);
    }

    return REASONS.includes(reason) ? t(`jfix.version_reason_${reason}`) : t('jfix.version_reason_other');
}

/**
 * The open chapter's history, from its own button and shortcut rather than
 * a list at the bottom of the Checks tab (ADR — Journey friction fixes).
 * Restoring asks first, in the app's own dialog, and saves the current text
 * to history before it changes anything (the restore endpoint does that).
 */
export default function VersionHistory({ title, versions = [], onRestore, onClose }) {
    const { t, formatNumber } = useI18n();

    return (
        <Dialog
            onClose={onClose}
            aria-labelledby="history-title"
            data-a="history:dialog"
            className="flex max-h-[85vh] w-full max-w-[520px] flex-col rounded-panel border border-border-default bg-bg-surface shadow-xl"
        >
            <div className="flex items-start justify-between gap-3 border-b border-border-divider px-5 py-4">
                <div className="min-w-0">
                    <h3 id="history-title" className="text-[15px] font-semibold text-text-primary">
                        {t('versions.title')}
                    </h3>
                    <p className="truncate text-[12.5px] text-text-secondary">{title}</p>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('common.close')}
                    className="grid h-8 w-8 shrink-0 place-items-center rounded-control text-text-secondary hover:bg-bg-subtle hover:text-text-primary"
                >
                    ×
                </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-3">
                {versions.length === 0 ? (
                    <p className="text-[12.5px] leading-relaxed text-text-secondary">{t('versions.none')}</p>
                ) : (
                    <ul className="space-y-2">
                        {versions.map((version) => (
                            <li key={version.id} className="flex items-start gap-3 rounded-tile border border-border-default p-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-[12.5px] font-semibold text-text-primary">
                                        v{version.version_number} · {versionReason(t, version.reason)}
                                    </p>
                                    {version.label && (
                                        <p className="mt-0.5 text-[12px] text-text-body">{version.label}</p>
                                    )}
                                    <p className="mt-0.5 text-[11.5px] text-text-secondary">
                                        {version.created_human} ·{' '}
                                        {t('book.words', { count: version.word_count, words: formatNumber(version.word_count) })}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    data-a={`history:restore:${version.id}`}
                                    onClick={() => onRestore(version.id)}
                                    className="shrink-0 rounded-control border border-border-input px-2.5 py-1.5 text-[12px] font-semibold text-text-primary hover:bg-bg-subtle"
                                >
                                    {t('jfix.restore')}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Dialog>
    );
}
