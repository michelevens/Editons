import { useEffect, useState } from 'react';
import { t, useI18n } from '../../i18n';

/** A date and time as toLocaleString() wrote it, in the reader's language. */
const WHEN = { year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' };

/**
 * What the author states before a book goes to the stores, on ISBN & Rights:
 * where each edition's ISBN comes from, the rights declaration, the answers
 * to KDP's AI-content questions, and the legal-deposit reminder.
 *
 * The app suggests; the author confirms. Each confirmation is recorded on
 * the server with who and when (RightsDeclaration, AiDisclosure).
 */
export default function PublishingDeclarations({ bookId, data, setData, onNotify }) {
    useI18n();
    const [busy, setBusy] = useState(null);
    const editable = data.can_edit;

    const send = async (key, request, done) => {
        setBusy(key);

        try {
            const response = await request();
            setData(response.data);
            if (done) onNotify?.(done);
        } catch (error) {
            const errors = error.response?.data?.errors;
            onNotify?.(
                (errors && Object.values(errors)[0]?.[0]) ??
                    error.response?.data?.message ??
                    t('declarations.not_saved'),
            );
        } finally {
            setBusy(null);
        }
    };

    return (
        <>
            <IsbnSources bookId={bookId} data={data} busy={busy} send={send} editable={editable} />
            <Declaration bookId={bookId} data={data} busy={busy} send={send} editable={editable} />
            <AiQuestions bookId={bookId} data={data} busy={busy} send={send} editable={editable} />
            <section
                data-a="rights:deposit"
                className="rounded-tile border border-border-default bg-bg-surface p-3.5"
            >
                <h3 className="mb-1 text-[13px] font-semibold text-text-primary">{t('declarations.deposit')}</h3>
                <p className="text-[12px] leading-snug text-text-body">
                    {data.legal_deposit.message}{' '}
                    {data.legal_deposit.source && (
                        <a
                            href={data.legal_deposit.source}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-brand-primary hover:underline"
                        >
                            {t('declarations.source')}
                        </a>
                    )}
                </p>
                {!data.legal_deposit.country && (
                    <p className="mt-1 text-[11px] text-text-secondary">
                        {t('declarations.set_country')}
                    </p>
                )}
            </section>
        </>
    );
}

const EDITION_LABELS = { ebook: 'declarations.edition_ebook', paperback: 'declarations.edition_paperback', hardcover: 'declarations.edition_hardcover' };

function IsbnSources({ bookId, data, busy, send, editable }) {
    const agency = data.isbn_agency;

    return (
        <section
            data-a="rights:isbn-plan"
            className="rounded-tile border border-border-default bg-bg-surface p-3.5"
        >
            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">{t('declarations.isbn_title')}</h3>
            <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                {t('declarations.isbn_help')}
            </p>
            <ul className="space-y-2">
                {Object.entries(data.isbn_plan).map(([edition, plan]) => (
                    <li key={edition}>
                        <fieldset className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <legend className="w-full text-[12px] font-medium text-text-primary sm:w-24">
                                {EDITION_LABELS[edition] ? t(EDITION_LABELS[edition]) : edition}
                            </legend>
                            {[
                                ['own', t('declarations.isbn_own')],
                                ['store', t('declarations.isbn_store')],
                            ].map(([source, label]) => (
                                <label key={source} className="inline-flex items-center gap-1.5 text-[12px] text-text-body">
                                    <input
                                        type="radio"
                                        name={`isbn-${edition}`}
                                        value={source}
                                        data-a={`rights:isbn-plan:${edition}:${source}`}
                                        checked={plan.source === source}
                                        disabled={!editable || busy !== null || (source === 'store' && plan.isbn !== null)}
                                        onChange={() =>
                                            send(`plan:${edition}`, () =>
                                                window.axios.patch(`/books/${bookId}/rights/isbn-plan`, { edition, source }),
                                            )
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                            {plan.source === 'own' && !plan.isbn && (
                                <span className="text-[11px] font-medium text-status-warning">
                                    {t('declarations.no_number')}
                                </span>
                            )}
                        </fieldset>
                    </li>
                ))}
            </ul>
            <p data-a="rights:agency" className="mt-2 text-[11px] leading-snug text-text-secondary">
                {t('declarations.agency_from')}{' '}
                <a href={agency.url} target="_blank" rel="noopener noreferrer" className="font-medium text-brand-primary hover:underline">
                    {agency.agency}
                </a>
                {agency.free === true && `, ${t('declarations.agency_free')}`}.
                {!agency.country && ` ${t('declarations.agency_country')}`}
            </p>
        </section>
    );
}

function Declaration({ bookId, data, busy, send, editable }) {
    const { formatDate: when } = useI18n();
    const declaration = data.declaration;
    const [materials, setMaterials] = useState(declaration.materials);
    const [holds, setHolds] = useState(false);

    useEffect(() => {
        setMaterials(declaration.materials);
    }, [declaration.confirmed_at]); // eslint-disable-line react-hooks/exhaustive-deps

    const update = (index, key, value) =>
        setMaterials((current) => current.map((item, i) => (i === index ? { ...item, [key]: value } : item)));

    const changed = JSON.stringify(materials) !== JSON.stringify(declaration.materials);

    return (
        <section
            data-a="rights:declaration"
            className="rounded-tile border border-border-default bg-bg-surface p-3.5"
        >
            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">{t('declarations.title')}</h3>
            <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                {t('declarations.help')}
            </p>

            {materials.length === 0 && (
                <p className="text-[11.5px] text-text-secondary">{t('declarations.none')}</p>
            )}

            <ul className="space-y-2">
                {materials.map((item, index) => (
                    <li key={index} className="grid grid-cols-1 gap-1.5 rounded-control border border-border-default p-2 sm:grid-cols-[150px_1fr_160px_auto]">
                        <select
                            aria-label={t('declarations.kind')}
                            value={item.kind}
                            data-a={`rights:material:${index}:kind`}
                            disabled={!editable}
                            onChange={(event) => update(index, 'kind', event.target.value)}
                            className="rounded-control border border-border-input px-2 py-1.5 text-[12px] focus:border-brand-primary focus:ring-0"
                        >
                            {Object.entries(declaration.kinds).map(([key, label]) => (
                                <option key={key} value={key}>{label}</option>
                            ))}
                        </select>
                        <input
                            aria-label={t('declarations.what')}
                            value={item.description}
                            data-a={`rights:material:${index}:description`}
                            placeholder={t('declarations.what_placeholder')}
                            disabled={!editable}
                            onChange={(event) => update(index, 'description', event.target.value)}
                            className="rounded-control border border-border-input px-2 py-1.5 text-[12px] focus:border-brand-primary focus:ring-0"
                        />
                        <select
                            aria-label={t('declarations.hold')}
                            value={item.permission}
                            data-a={`rights:material:${index}:permission`}
                            disabled={!editable}
                            onChange={(event) => update(index, 'permission', event.target.value)}
                            className="rounded-control border border-border-input px-2 py-1.5 text-[12px] focus:border-brand-primary focus:ring-0"
                        >
                            {Object.entries(declaration.permissions).map(([key, label]) => (
                                <option key={key} value={key}>{label}</option>
                            ))}
                        </select>
                        {editable && (
                            <button
                                type="button"
                                onClick={() => setMaterials((current) => current.filter((_, i) => i !== index))}
                                className="justify-self-start text-[11.5px] text-text-secondary hover:text-status-danger"
                            >
                                {t('declarations.remove')}
                            </button>
                        )}
                    </li>
                ))}
            </ul>

            {editable && (
                <>
                    <button
                        type="button"
                        data-a="rights:material:add"
                        onClick={() =>
                            setMaterials((current) => [...current, { kind: 'quote', description: '', permission: 'permission' }])
                        }
                        className="mt-2 text-[11.5px] font-semibold text-brand-primary hover:underline"
                    >
                        {t('declarations.add')}
                    </button>

                    <label className="mt-3 flex items-start gap-2 text-[12px] leading-snug text-text-body">
                        <input
                            type="checkbox"
                            checked={holds}
                            data-a="rights:declaration:holds"
                            onChange={(event) => setHolds(event.target.checked)}
                            className="mt-0.5"
                        />
                        {t('declarations.holds')}
                    </label>

                    <button
                        type="button"
                        data-a="rights:declaration:confirm"
                        disabled={!holds || busy !== null}
                        onClick={() =>
                            send(
                                'declaration',
                                () => window.axios.put(`/books/${bookId}/rights/declaration`, { holds_rights: true, materials }),
                                t('declarations.confirmed_toast'),
                            )
                        }
                        className="mt-2 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                    >
                        {declaration.confirmed && !changed ? t('declarations.confirm_again') : t('declarations.confirm')}
                    </button>
                </>
            )}

            <p data-a="rights:declaration:status" className="mt-2 text-[11px] text-text-secondary">
                {declaration.confirmed
                    ? `${t('declarations.confirmed_by', { name: declaration.confirmed_by, date: when(declaration.confirmed_at, WHEN) })}${changed ? ` ${t('declarations.unsaved')}` : ''}`
                    : t('declarations.not_confirmed')}
            </p>
        </section>
    );
}

const ANSWERS = {
    none: 'declarations.ai_none',
    assisted: 'declarations.ai_assisted',
    generated: 'declarations.ai_generated',
};

const CATEGORY_LABELS = { text: 'declarations.cat_text', images: 'declarations.cat_images', translations: 'declarations.cat_translations' };

function AiQuestions({ bookId, data, busy, send, editable }) {
    const { formatDate: when } = useI18n();
    const ai = data.ai;
    const [answers, setAnswers] = useState(ai.answers ?? ai.suggested);
    const [sure, setSure] = useState(false);
    const evidence = ai.evidence;

    useEffect(() => {
        setAnswers(ai.answers ?? ai.suggested);
    }, [ai.confirmed_at]); // eslint-disable-line react-hooks/exhaustive-deps

    const lines = {
        text: [
            evidence.text.ai_drafts > 0 &&
                t('declarations.ev_drafted', { chapters: evidence.text.ai_drafted_chapters, drafts: evidence.text.ai_drafts }),
            evidence.text.accepted_ai_fixes > 0 && t('declarations.ev_fixes', { count: evidence.text.accepted_ai_fixes }),
            evidence.text.ai_suggestions_applied > 0 && t('declarations.ev_suggestions', { count: evidence.text.ai_suggestions_applied }),
        ].filter(Boolean),
        images: [
            evidence.images.ai_cover &&
                (evidence.images.ai_cover.model ? t('declarations.ev_cover_model', { model: evidence.images.ai_cover.model }) : t('declarations.ev_cover')),
        ].filter(Boolean),
        translations: [evidence.translations.translation_edition && t('declarations.ev_translation')].filter(Boolean),
    };

    return (
        <section data-a="rights:ai" className="rounded-tile border border-border-default bg-bg-surface p-3.5">
            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">{t('declarations.ai_title')}</h3>
            <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                {t('declarations.ai_help')}{' '}
                <a href={ai.source} target="_blank" rel="noopener noreferrer" className="font-medium text-brand-primary hover:underline">
                    {t('declarations.ai_guidelines')}
                </a>
            </p>

            <div className="space-y-3">
                {Object.keys(CATEGORY_LABELS).map((category) => (
                    <fieldset key={category} data-a={`rights:ai:${category}`}>
                        <legend className="text-[12px] font-medium text-text-primary">{t(CATEGORY_LABELS[category])}</legend>
                        <p className="text-[11px] text-text-secondary">
                            {lines[category].length ? lines[category].join(' ') : t('declarations.no_ai_recorded')} {t('declarations.suggested')}{' '}
                            <span className="font-medium text-text-body">{ANSWERS[ai.suggested[category]] ? t(ANSWERS[ai.suggested[category]]) : undefined}</span>.
                        </p>
                        <div className="mt-1 flex flex-col gap-1">
                            {Object.entries(ANSWERS).map(([value, label]) => (
                                <label key={value} className="inline-flex items-center gap-1.5 text-[12px] text-text-body">
                                    <input
                                        type="radio"
                                        name={`ai-${category}`}
                                        value={value}
                                        data-a={`rights:ai:${category}:${value}`}
                                        checked={answers[category] === value}
                                        disabled={!editable}
                                        onChange={() => setAnswers((current) => ({ ...current, [category]: value }))}
                                    />
                                    {t(label)}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                ))}
            </div>

            {ai.outdated.length > 0 && (
                <p role="status" className="mt-2 text-[11.5px] font-medium text-status-warning">
                    {t('declarations.outdated', { list: ai.outdated.join(', ') })}
                </p>
            )}

            {editable && (
                <>
                    <label className="mt-3 flex items-start gap-2 text-[12px] leading-snug text-text-body">
                        <input
                            type="checkbox"
                            checked={sure}
                            data-a="rights:ai:sure"
                            onChange={(event) => setSure(event.target.checked)}
                            className="mt-0.5"
                        />
                        {t('declarations.sure')}
                    </label>
                    <button
                        type="button"
                        data-a="rights:ai:confirm"
                        disabled={!sure || busy !== null}
                        onClick={() =>
                            send(
                                'ai',
                                () => window.axios.put(`/books/${bookId}/rights/ai-disclosure`, { confirm: true, answers }),
                                t('declarations.ai_confirmed_toast'),
                            )
                        }
                        className="mt-2 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                    >
                        {t('declarations.confirm_answers')}
                    </button>
                </>
            )}

            <p data-a="rights:ai:status" className="mt-2 text-[11px] text-text-secondary">
                {ai.confirmed_at
                    ? `${t('declarations.confirmed_by', { name: ai.confirmed_by, date: when(ai.confirmed_at, WHEN) })} ${t('declarations.at_kdp', {
                          text: ai.kdp.text ? t('declarations.yes') : t('declarations.no'),
                          images: ai.kdp.images ? t('declarations.yes') : t('declarations.no'),
                          translations: ai.kdp.translations ? t('declarations.yes') : t('declarations.no'),
                      })}`
                    : ai.required
                      ? t('declarations.ai_required')
                      : t('declarations.ai_optional')}
            </p>
        </section>
    );
}
