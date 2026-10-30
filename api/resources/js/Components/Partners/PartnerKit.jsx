/**
 * Small pieces shared by the partner pages (apply, dashboard, invitation).
 * A module of its own: a page imported by another page would be folded into
 * a shared chunk and lose its own manifest entry.
 */
import { useI18n } from '@/i18n';

export const field =
    'w-full rounded-input border border-border-input bg-bg-surface px-3 py-2 text-[14px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20';

export function Agreement({ agreement }) {
    const { t } = useI18n();

    return (
        <details className="rounded-tile border border-border-default bg-bg-subtle/50 p-3" data-a="partners:agreement">
            <summary className="cursor-pointer text-[13px] font-semibold text-text-primary">
                {t('partner.agreement_read', { version: agreement.version })}
            </summary>
            {agreement.draft && (
                <p className="mt-2 rounded-badge bg-status-warning-bg px-2 py-1 text-[11.5px] font-semibold text-status-warning">
                    {t('partner.agreement_draft')}
                </p>
            )}
            <div className="mt-2 max-h-[320px] overflow-y-auto whitespace-pre-line text-[12.5px] leading-relaxed text-text-body">{agreement.text}</div>
        </details>
    );
}

export function Row({ label, htmlFor, error, hint = null, children }) {
    return (
        <div>
            <label htmlFor={htmlFor} className="text-[13px] font-medium text-text-primary">
                {label}
            </label>
            <div className="mt-1">{children}</div>
            {hint && <p className="mt-1 text-[11.5px] text-text-secondary">{hint}</p>}
            {error && <p className="mt-1 text-[12px] text-status-danger">{error}</p>}
        </div>
    );
}
