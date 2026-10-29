import { useEffect, useRef } from 'react';
import { useI18n } from '@/i18n';

/**
 * The pieces every auth screen shares.
 *
 * Breeze ships InputLabel, TextInput and InputError as separate components,
 * which means each page re-assembles the same three-part field by hand and
 * they drift. One component per field keeps the label, control, error and
 * optional action locked together.
 */

export function AuthHeading({ title, subtitle }) {
    return (
        <header className="mb-7">
            <h1 className="font-display text-[28px] font-bold leading-tight tracking-[-0.02em] text-text-primary">
                {title}
            </h1>
            {subtitle && (
                <p className="mt-2 text-[14px] leading-relaxed text-text-secondary">
                    {subtitle}
                </p>
            )}
        </header>
    );
}

export function AuthField({
    id,
    label,
    type = 'text',
    value,
    error,
    hint,
    action,
    isFocused = false,
    onChange,
    ...rest
}) {
    const input = useRef(null);

    useEffect(() => {
        if (isFocused) input.current?.focus();
    }, [isFocused]);

    return (
        <div>
            <div className="mb-1.5 flex items-baseline justify-between gap-3">
                <label
                    htmlFor={id}
                    className="text-[13px] font-semibold text-text-primary"
                >
                    {label}
                </label>
                {action}
            </div>

            <input
                id={id}
                ref={input}
                name={id}
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                // aria-invalid rather than colour alone: the red ring is
                // invisible to a screen reader and to anyone who cannot
                // distinguish it.
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
                className={`h-[46px] w-full rounded-xl border bg-bg-surface px-3.5 text-[14px] text-text-primary transition placeholder:text-text-secondary focus:outline-none focus:ring-4 ${
                    error
                        ? 'border-status-danger focus:border-status-danger focus:ring-status-danger/10'
                        : 'border-border-input focus:border-brand-primary focus:ring-brand-primary/10'
                }`}
                {...rest}
            />

            {hint && !error && (
                <p id={`${id}-hint`} className="mt-1.5 text-[12px] text-text-secondary">
                    {hint}
                </p>
            )}

            {error && (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="mt-1.5 text-[12.5px] font-medium text-status-danger"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

export function AuthSubmit({ processing, children }) {
    const { t } = useI18n();

    return (
        <button
            type="submit"
            disabled={processing}
            className="flex h-[46px] w-full items-center justify-center gap-2 rounded-xl bg-brand-fill text-[14.5px] font-semibold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)] transition hover:bg-brand-primary-hover disabled:cursor-not-allowed disabled:opacity-70"
        >
            {processing ? (
                <>
                    <span
                        aria-hidden="true"
                        className="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"
                    />
                    {t('signin.working')}
                </>
            ) : (
                <>
                    {children}
                    <svg
                        aria-hidden="true"
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M5 12h14M13 6l6 6-6 6" />
                    </svg>
                </>
            )}
        </button>
    );
}
