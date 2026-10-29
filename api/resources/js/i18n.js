import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import en from '../../lang/en.json';

/**
 * The interface languages in the browser (ADR — Interface languages:
 * English, French, Haitian Creole).
 *
 *     const { t, formatNumber } = useI18n();
 *     t('shelf.count', { count: 3 })        // "3 books" / "3 livres" / "3 liv"
 *
 * The catalogues are lang/{en,fr,ht}.json, shared with Laravel
 * (App\I18n\Locales). English is bundled here, so it is always at hand as
 * the fallback and an English page needs nothing from the server; another
 * language's strings arrive once, as the `i18n_messages` prop.
 *
 * Messages use the ICU subset of App\I18n\MessageFormat: {name},
 * {count, plural, one {# book} other {# books}} and
 * {kind, select, pdf {PDF} other {file}}. A key missing from the current
 * language is the English; missing from English too (the static scan in
 * tests/Feature/I18n prevents it) it is the key's last part humanised,
 * never the raw key, and the console says so in development.
 *
 * Keys passed through a variable cannot be checked by that scan. Name them
 * where they are defined as `tKey: 'nav.books'`, which it reads too.
 */

const ENGLISH = Object.fromEntries(Object.entries(en).filter(([key]) => key !== '_meta'));

let current = { locale: 'en', intl: 'en-US', messages: {} };
const warned = new Set();

/** Plural categories, as MessageFormat::category() on the server. */
function category(n, locale) {
    if (locale === 'ht') return 'other';
    if (locale === 'fr') return n >= 0 && n < 2 ? 'one' : 'other';

    return n === 1 ? 'one' : 'other';
}

function closing(text, open) {
    let depth = 0;

    for (let j = open; j < text.length; j += 1) {
        if (text[j] === '{') depth += 1;
        else if (text[j] === '}') {
            depth -= 1;
            if (depth === 0) return j;
        }
    }

    return -1;
}

function branches(text) {
    const options = {};
    let i = 0;

    while (i < text.length) {
        const open = text.indexOf('{', i);

        if (open === -1) break;

        const close = closing(text, open);

        if (close === -1) break;

        options[text.slice(i, open).trim()] = text.slice(open + 1, close);
        i = close + 1;
    }

    return options;
}

function argument(body, params, locale) {
    const first = body.indexOf(',');

    if (first === -1) {
        const name = body.trim();

        return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : `{${body}}`;
    }

    const second = body.indexOf(',', first + 1);
    const name = body.slice(0, first).trim();
    const type = body.slice(first + 1, second === -1 ? undefined : second).trim();

    if (second === -1) return `{${body}}`;

    const options = branches(body.slice(second + 1));
    const value = params[name];

    if (type === 'plural') {
        const n = Number(value) || 0;
        const chosen = options[`=${n}`] ?? options[category(n, locale)] ?? options.other ?? '';

        // English prints the bare number, as the screens always did.
        const shown = locale === 'en' ? String(n) : formatNumber(n, locale);

        return format(chosen.replaceAll('#', shown), params, locale);
    }

    return format(options[String(value)] ?? options.other ?? '', params, locale);
}

/** Fill a message's {placeholders}. Exported for tests and odd callers. */
export function format(message, params = {}, locale = current.locale) {
    if (!message.includes('{')) return message;

    let out = '';

    for (let i = 0; i < message.length; i += 1) {
        if (message[i] !== '{') {
            out += message[i];
            continue;
        }

        const end = closing(message, i);

        if (end === -1) {
            out += message.slice(i);
            break;
        }

        out += argument(message.slice(i + 1, end), params, locale);
        i = end;
    }

    return out;
}

function lookup(key, catalogue) {
    const own = catalogue.messages?.[key];

    if (typeof own === 'string') return own;

    if (catalogue.locale !== 'en') warn(key, catalogue.locale);

    if (typeof ENGLISH[key] === 'string') return ENGLISH[key];

    warn(key, 'en');

    const last = String(key).split('.').pop() ?? '';

    return last.charAt(0).toUpperCase() + last.slice(1).replaceAll('_', ' ');
}

function warn(key, locale) {
    if (!import.meta.env?.DEV || warned.has(`${locale}|${key}`)) return;

    warned.add(`${locale}|${key}`);
    // eslint-disable-next-line no-console
    console.warn(`[i18n] missing "${key}" in ${locale}.json`);
}

function translate(catalogue, key, params) {
    return format(lookup(key, catalogue), params ?? {}, catalogue.locale);
}

/**
 * A string in the current language, outside a component (a toast fired
 * from a callback, a module-level helper). Components use useI18n(), which
 * re-renders them when the language changes.
 */
export function t(key, params) {
    return translate(current, key, params);
}

/** The language in force: 'en', 'fr' or 'ht'. */
export function currentLocale() {
    return current.locale;
}

/**
 * Numbers in the reader's conventions. English passes no locale at all, as
 * every toLocaleString() in the app did before, so English screens are
 * unchanged on any machine.
 */
export function formatNumber(n, locale = current.locale, options) {
    const tag = locale === 'en' ? undefined : locale === current.locale ? current.intl : intlFor(locale);

    return Number(n).toLocaleString(tag, options);
}

/** Money from minor units: 1299, 'USD' → "$12.99" / "12,99 $US". */
export function formatMoney(cents, currency = 'USD', locale = current.locale) {
    return formatNumber((Number(cents) || 0) / 100, locale, { style: 'currency', currency });
}

/**
 * A date. Kreyòl has no CLDR data, so its month and weekday names are the
 * catalogue's (date.months, date.weekdays) over Haiti's French layout.
 */
export function formatDate(value, options = { dateStyle: 'medium' }, locale = current.locale) {
    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) return '';

    const tag = locale === 'en' ? undefined : intlFor(locale);

    if (locale !== 'ht') return new Intl.DateTimeFormat(tag, options).format(date);

    const months = lookup('date.months', current).split(',');
    const weekdays = lookup('date.weekdays', current).split(',');
    const parts = new Intl.DateTimeFormat(tag, expandStyle(options)).formatToParts(date);

    return parts
        .map((part) => {
            if (part.type === 'month' && !/^\d+$/.test(part.value)) return months[date.getMonth()] ?? part.value;
            if (part.type === 'weekday') return weekdays[date.getDay()] ?? part.value;

            return part.value;
        })
        .join('');
}

// formatToParts gives a "month" part only for explicit fields, so the
// dateStyle shorthands are spelled out for Kreyòl.
function expandStyle(options) {
    const { dateStyle, ...rest } = options;

    switch (dateStyle) {
        case 'full':
            return { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', ...rest };
        case 'long':
            return { day: 'numeric', month: 'long', year: 'numeric', ...rest };
        case 'medium':
            return { day: 'numeric', month: 'short', year: 'numeric', ...rest };
        case 'short':
            return { day: '2-digit', month: '2-digit', year: 'numeric', ...rest };
        default:
            return options;
    }
}

function intlFor(locale) {
    return { fr: 'fr-FR', ht: 'fr-HT' }[locale] ?? 'en-US';
}

/**
 * The current language, its t() and formatters. Every component that shows
 * interface text calls this; it also keeps the module-level t() in step.
 */
export function useI18n() {
    const props = usePage().props;
    const locale = props.i18n?.locale ?? 'en';
    const messages = locale === 'en' ? {} : (props.i18n_messages ?? {});

    if (current.locale !== locale || current.messages !== messages) {
        current = { locale, intl: props.i18n?.intl ?? intlFor(locale), messages };
    }

    const catalogue = current;

    return {
        locale,
        locales: props.i18n?.locales ?? [{ code: 'en', name: 'English' }],
        t: (key, params) => translate(catalogue, key, params),
        formatNumber: (n, options) => formatNumber(n, locale, options),
        formatMoney: (cents, currency) => formatMoney(cents, currency, locale),
        formatDate: (value, options) => formatDate(value, options, locale),
    };
}

/** <html lang> follows the language as it changes without a full load. */
export function useDocumentLanguage() {
    const locale = usePage().props.i18n?.locale ?? 'en';

    useEffect(() => {
        document.documentElement.lang = locale;
    }, [locale]);
}

/** Switch language: stored on the account, or the session when signed out. */
export function switchLocale(locale) {
    router.put(route('locale.update'), { locale }, { preserveScroll: true, preserveState: false });
}
