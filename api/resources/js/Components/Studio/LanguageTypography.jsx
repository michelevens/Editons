import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * Language and typography on Book Settings (ADR — Book language and
 * typography).
 *
 * The browser's spell-check in the editor, and the book's typography
 * rules: quotes, French spacing, dashes and the ellipsis. Each rule reads
 * "Language default" until the author picks one, so a French book gets
 * « guillemets » and an English one “curly” quotes without anyone choosing.
 * Nothing changes the manuscript unless the author asks: corrections as you
 * type, at export, or the one-click pass, which counts first and keeps a
 * version of every chapter it changes.
 *
 * Paragraphs and chapter labels are the interior design's (Design &
 * Layout), shown here only to say where they are set.
 */

/** A BCP 47 tag: "fr", "fr-CA", "pt-BR", "zh-Hant". */
const BCP47 = '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

const OTHER = '__other';

/** The book's language: the offered list, or a language code of its own. */
export function LanguageField({ value, languages, error, onChange }) {
    const { t } = useI18n();
    const [other, setOther] = useState(false);
    const choosing = other || (value && !languages.includes(value));

    return (
        <div>
            <label>
                <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                    {t('language.label')}
                </span>
                <select
                    id="language"
                    value={choosing ? OTHER : value}
                    data-a="settings:field:language"
                    onChange={(event) => {
                        if (event.target.value === OTHER) {
                            setOther(true);

                            return;
                        }

                        setOther(false);
                        onChange(event.target.value);
                    }}
                    className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                >
                    {languages.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                    <option value={OTHER}>{t('typo.language_other')}</option>
                </select>
            </label>

            {choosing && (
                <label className="mt-2 block">
                    <span className="sr-only">{t('typo.language_code')}</span>
                    <input
                        type="text"
                        value={languages.includes(value) ? '' : value ?? ''}
                        pattern={BCP47}
                        maxLength={35}
                        placeholder="fr-CA"
                        data-a="settings:field:language-code"
                        aria-label={t('typo.language_code')}
                        aria-invalid={error ? 'true' : undefined}
                        onChange={(event) => onChange(event.target.value.trim())}
                        className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                    />
                    <span className="mt-1 block text-[11.5px] text-text-secondary">{t('typo.language_code_hint')}</span>
                </label>
            )}
        </div>
    );
}

export default function LanguageTypography({ book, typography, interior }) {
    const { t } = useI18n();
    const [spellcheck, setSpellcheck] = useState(book.settings?.spellcheck ?? true);
    const [stored, setStored] = useState(typography.stored);
    const [busy, setBusy] = useState(null);
    const defaults = typography.defaults;

    const save = (key, settings, rollback) => {
        setBusy(key);
        router.patch(
            route('books.update', book.id),
            { settings },
            {
                preserveScroll: true,
                preserveState: true,
                onError: rollback,
                onFinish: () => setBusy(null),
            },
        );
    };

    const setRule = (key, value) => {
        const before = stored;

        setStored({ ...stored, [key]: value });
        save(key, { typography: { [key]: value } }, () => setStored(before));
    };

    const toggleSpellcheck = () => {
        const next = !spellcheck;

        setSpellcheck(next);
        save('spellcheck', { spellcheck: next }, () => setSpellcheck(!next));
    };

    const onOff = (on) => (on ? t('typo.on') : t('typo.off'));
    const quoteName = (q) => t(`typo.quotes_${q}`);

    // A rule's select: "Language default (…)", then each choice.
    const choices = {
        quotes: [
            { value: '', label: t('typo.language_default', { value: quoteName(defaults.quotes) }) },
            ...['curly', 'guillemets', 'none'].map((q) => ({ value: q, label: quoteName(q) })),
        ],
        ...Object.fromEntries(['french_spacing', 'dashes', 'ellipsis'].map((key) => [key, [
            { value: '', label: t('typo.language_default', { value: onOff(defaults[key]) }) },
            { value: 'on', label: t('typo.on') },
            { value: 'off', label: t('typo.off') },
        ]])),
    };

    const ruleValue = (key) => {
        const v = stored[key];

        if (v === null || v === undefined) return '';
        if (key === 'quotes') return v;

        return v ? 'on' : 'off';
    };

    const pickRule = (key, value) => {
        if (value === '') return setRule(key, null);
        if (key === 'quotes') return setRule(key, value);

        return setRule(key, value === 'on');
    };

    const rules = [
        { key: 'quotes', title: t('typo.quotes'), detail: t('typo.quotes_detail') },
        { key: 'french_spacing', title: t('typo.french_spacing'), detail: t('typo.french_spacing_detail') },
        { key: 'dashes', title: t('typo.dashes'), detail: t('typo.dashes_detail') },
        { key: 'ellipsis', title: t('typo.ellipsis'), detail: t('typo.ellipsis_detail') },
    ];

    const switches = [
        { key: 'as_you_type', title: t('typo.as_you_type'), detail: t('typo.as_you_type_detail') },
        { key: 'apply_at_export', title: t('typo.apply_at_export'), detail: t('typo.apply_at_export_detail') },
    ];

    return (
        <section
            className="mt-5 rounded-panel border border-border-default bg-bg-surface p-6"
            data-a="settings:typography"
            aria-labelledby="typography-heading"
        >
            <h2 id="typography-heading" className="text-[15px] font-semibold text-text-primary">{t('typo.title')}</h2>
            <p className="mt-1 text-[12.5px] leading-relaxed text-text-secondary">
                {t('typo.intro', { code: typography.language })}
            </p>

            <ul className="mt-2 divide-y divide-border-divider">
                <Row title={t('typo.spellcheck')} detail={t('typo.spellcheck_detail', { code: typography.language })}>
                    <Switch
                        on={spellcheck}
                        label={t('typo.spellcheck')}
                        name="spellcheck"
                        disabled={busy === 'spellcheck'}
                        onClick={toggleSpellcheck}
                    />
                </Row>

                {rules.map((rule) => (
                    <Row key={rule.key} title={rule.title} detail={rule.detail}>
                        <select
                            value={ruleValue(rule.key)}
                            aria-label={rule.title}
                            data-a={`settings:typography:${rule.key}`}
                            disabled={busy === rule.key}
                            onChange={(event) => pickRule(rule.key, event.target.value)}
                            className="h-[36px] w-full max-w-full rounded-control border border-border-input px-2.5 text-[13px] focus:border-brand-primary focus:ring-0 sm:w-auto"
                        >
                            {choices[rule.key].map((choice) => (
                                <option key={choice.value} value={choice.value}>
                                    {choice.label}
                                </option>
                            ))}
                        </select>
                    </Row>
                ))}

                {switches.map((row) => (
                    <Row key={row.key} title={row.title} detail={row.detail}>
                        <Switch
                            on={!!stored[row.key]}
                            label={row.title}
                            name={`typography:${row.key}`}
                            disabled={busy === row.key}
                            onClick={() => setRule(row.key, !stored[row.key])}
                        />
                    </Row>
                ))}
            </ul>

            <FixTypography book={book} disabled={busy !== null} />

            <div className="mt-5 rounded-control border border-border-default bg-bg-subtle/60 p-4" data-a="settings:interior">
                <h3 className="text-[13.5px] font-semibold text-text-primary">{t('typo.paragraphs_title')}</h3>
                <dl className="mt-2 grid grid-cols-1 gap-x-6 gap-y-1.5 text-[12.5px] sm:grid-cols-[auto_1fr]">
                    <dt className="text-text-secondary">{t('layout.paragraphs')}</dt>
                    <dd className="font-medium text-text-primary">
                        {interior.paragraph === 'block' ? t('layout.space_between') : t('layout.first_line_indent')}
                    </dd>
                    <dt className="text-text-secondary">{t('layout.chapter_label')}</dt>
                    <dd className="font-medium text-text-primary">{t(`layout.label_${interior.label}`)}</dd>
                    <dt className="text-text-secondary">{t('layout.numbering')}</dt>
                    <dd className="font-medium text-text-primary">{t(`layout.numbering_${interior.numbering ?? 'auto'}`)}</dd>
                </dl>
                <p className="mt-2 text-[12px] leading-relaxed text-text-secondary">{t('typo.paragraphs_detail')}</p>
                <Link
                    href={route('design.show', book.id)}
                    data-a="settings:open-design"
                    className="mt-2 inline-flex text-[12.5px] font-semibold text-brand-primary hover:underline"
                >
                    {t('typo.open_design')}
                </Link>
            </div>
        </section>
    );
}

/**
 * Count, then fix. The count changes nothing; the fix saves a version of
 * every chapter it changes before changing it.
 */
function FixTypography({ book, disabled }) {
    const { t } = useI18n();
    // idle | counting | counted | fixing | fixed | error
    const [state, setState] = useState('idle');
    const [result, setResult] = useState(null);

    const count = async () => {
        setState('counting');

        try {
            const { data } = await window.axios.get(route('typography.preview', book.id));

            setResult(data);
            setState('counted');
        } catch {
            setState('error');
        }
    };

    const fix = async () => {
        setState('fixing');

        try {
            const { data } = await window.axios.post(route('typography.fix', book.id));

            setResult(data);
            setState('fixed');
        } catch {
            setState('error');
        }
    };

    const button = 'h-[34px] rounded-control px-3.5 text-[13px] font-semibold transition disabled:opacity-60';

    return (
        <div className="mt-4 border-t border-border-divider pt-4" data-a="settings:fix-typography" aria-live="polite">
            <p className="text-[13.5px] font-medium text-text-primary">{t('typo.fix_title')}</p>
            <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-secondary">{t('typo.fix_detail')}</p>

            {state === 'counted' && result && (
                <div className="mt-3 text-[13px]" data-a="settings:fix-typography:count">
                    {result.changes === 0 ? (
                        <p className="text-text-secondary">{t('typo.fix_none')}</p>
                    ) : (
                        <>
                            <p className="font-medium text-text-primary">
                                {t('typo.fix_count', { count: result.changes, chapters: result.chapters.length })}
                            </p>
                            <ul className="mt-1.5 max-h-40 overflow-y-auto text-[12.5px] text-text-secondary">
                                {result.chapters.map((chapter) => (
                                    <li key={chapter.id} className="flex justify-between gap-4 py-0.5">
                                        <span className="min-w-0 truncate">{chapter.title}</span>
                                        <span className="shrink-0 tabular-nums">{chapter.changes}</span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            )}

            {state === 'fixed' && result && (
                <p className="mt-3 text-[13px] font-medium text-status-success" data-a="settings:fix-typography:done">
                    {result.changes === 0 ? t('typo.fix_none') : t('typo.fix_done', { count: result.changes, chapters: result.chapters.length })}
                </p>
            )}

            {state === 'error' && (
                <p role="alert" className="mt-3 text-[12.5px] font-medium text-status-danger">{t('typo.fix_error')}</p>
            )}

            <div className="mt-3 flex flex-wrap gap-2">
                {state === 'counted' && result?.changes > 0 ? (
                    <>
                        <button
                            type="button"
                            data-a="settings:fix-typography:cancel"
                            onClick={() => setState('idle')}
                            className={`${button} border border-border-input bg-bg-surface text-text-body`}
                        >
                            {t('common.cancel')}
                        </button>
                        <button
                            type="button"
                            data-a="settings:fix-typography:apply"
                            onClick={fix}
                            className={`${button} bg-brand-fill text-white hover:bg-brand-primary-hover`}
                        >
                            {t('typo.fix_apply', { count: result.changes })}
                        </button>
                    </>
                ) : (
                    <button
                        type="button"
                        data-a="settings:fix-typography:preview"
                        disabled={disabled || state === 'counting' || state === 'fixing'}
                        onClick={count}
                        className={`${button} border border-border-input bg-bg-surface text-text-body hover:bg-bg-subtle`}
                    >
                        {state === 'counting' || state === 'fixing' ? t('typo.fix_working') : t('typo.fix_preview')}
                    </button>
                )}
            </div>
        </div>
    );
}

function Row({ title, detail, children }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 py-3.5">
            <div className="min-w-0 flex-1 basis-[240px]">
                <p className="text-[13.5px] font-medium text-text-primary">{title}</p>
                <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-secondary">{detail}</p>
            </div>
            {children}
        </li>
    );
}

function Switch({ on, label, name, disabled, onClick }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            aria-label={label}
            data-a={`settings:toggle:${name}`}
            disabled={disabled}
            onClick={onClick}
            className={`relative h-[24px] w-[42px] shrink-0 rounded-full transition disabled:opacity-60 ${
                on ? 'bg-brand-fill' : 'bg-border-input'
            }`}
        >
            <span
                className={`absolute top-[3px] h-[18px] w-[18px] rounded-full bg-white transition-all ${
                    on ? 'left-[21px]' : 'left-[3px]'
                }`}
            />
        </button>
    );
}
