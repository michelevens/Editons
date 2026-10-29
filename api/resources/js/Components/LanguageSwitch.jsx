import { switchLocale, useI18n } from '@/i18n';

/**
 * The interface language: English, Français, Kreyòl ayisyen.
 *
 * Each language is named in itself, so somebody who cannot read the
 * current one still finds their own. On the sign-in pages (GuestLayout) it
 * is stored for the session and kept by the account created from it; in
 * the account menu and Account settings it is the account's.
 *
 * `variant="menu"` is the segmented control of the account menu, like the
 * appearance switch beside it; the default is a compact select.
 */
export default function LanguageSwitch({ className = '', variant = 'select' }) {
    const { t, locale, locales } = useI18n();

    if (variant === 'menu') {
        return (
            <div className={className}>
                <p id="language-label" className="mb-1.5 text-[11.5px] font-medium text-text-secondary">
                    {t('language.label')}
                </p>
                <div
                    role="group"
                    aria-labelledby="language-label"
                    className="grid grid-cols-3 gap-1 rounded-control bg-bg-subtle p-0.5"
                >
                    {locales.map((option) => (
                        <button
                            key={option.code}
                            type="button"
                            role="menuitemradio"
                            lang={option.code}
                            aria-checked={locale === option.code}
                            data-a={`locale:${option.code}`}
                            title={option.name}
                            onClick={() => locale !== option.code && switchLocale(option.code)}
                            className={`flex h-[28px] min-w-0 items-center justify-center truncate rounded-[5px] px-1 text-[12px] transition ${
                                locale === option.code
                                    ? 'bg-bg-surface font-semibold text-text-primary shadow-floatingButton'
                                    : 'text-text-secondary hover:text-text-primary'
                            }`}
                        >
                            {short(option)}
                        </button>
                    ))}
                </div>
                {locale === 'ht' && (
                    <p className="mt-1.5 text-[11px] leading-snug text-text-secondary">{t('language.draft')}</p>
                )}
            </div>
        );
    }

    return (
        <div className={className}>
            <label className="flex items-center gap-2 text-[12.5px] text-text-secondary">
                <span>{t('language.label')}</span>
                <select
                    data-a="locale:select"
                    value={locale}
                    onChange={(event) => switchLocale(event.target.value)}
                    className="h-[30px] rounded-control border border-border-input bg-bg-surface py-0 pl-2 pr-7 text-[12.5px] text-text-primary focus:border-brand-primary focus:outline-none"
                >
                    {locales.map((option) => (
                        <option key={option.code} value={option.code} lang={option.code}>
                            {option.name}
                        </option>
                    ))}
                </select>
            </label>
        </div>
    );
}

// "Kreyòl ayisyen" does not fit a third of a 264px menu.
function short(option) {
    return option.code === 'ht' ? 'Kreyòl' : option.name;
}
