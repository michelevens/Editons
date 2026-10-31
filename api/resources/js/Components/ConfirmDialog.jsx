import Dialog from './Dialog';
import { useI18n } from '@/i18n';

/**
 * A yes/no question in the app's own words and language, instead of the
 * browser's confirm(), which cannot be translated, styled or tested
 * (ADR — Journey friction fixes).
 *
 * Focus starts on the safe choice; Escape and the scrim cancel.
 */
export default function ConfirmDialog({ title, message, confirmLabel, onConfirm, onCancel, busy = false, danger = false }) {
    const { t } = useI18n();

    return (
        <Dialog
            onClose={() => !busy && onCancel()}
            role="alertdialog"
            aria-labelledby="confirm-title"
            aria-describedby="confirm-message"
            className="w-full max-w-[420px] rounded-panel border border-border-default bg-bg-surface p-5 shadow-xl"
        >
            <h3 id="confirm-title" className="text-[15px] font-semibold text-text-primary">
                {title}
            </h3>
            {message && (
                <p id="confirm-message" className="mt-1.5 text-[13px] leading-relaxed text-text-secondary">
                    {message}
                </p>
            )}
            <div className="mt-4 flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    data-autofocus
                    data-a="confirm:cancel"
                    onClick={onCancel}
                    disabled={busy}
                    className="h-[36px] rounded-control border border-border-input px-3.5 text-[13px] text-text-primary hover:bg-bg-subtle disabled:opacity-50"
                >
                    {t('common.cancel')}
                </button>
                <button
                    type="button"
                    data-a="confirm:ok"
                    onClick={onConfirm}
                    disabled={busy}
                    className={`h-[36px] rounded-control px-4 text-[13px] font-semibold text-white disabled:opacity-50 ${
                        danger ? 'bg-status-danger-fill hover:opacity-90' : 'bg-brand-fill hover:bg-brand-primary-hover'
                    }`}
                >
                    {confirmLabel}
                </button>
            </div>
        </Dialog>
    );
}
