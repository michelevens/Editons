import { useEffect, useState } from 'react';

/**
 * What the author states before a book goes to the stores, on ISBN & Rights:
 * where each edition's ISBN comes from, the rights declaration, the answers
 * to KDP's AI-content questions, and the legal-deposit reminder.
 *
 * The app suggests; the author confirms. Each confirmation is recorded on
 * the server with who and when (RightsDeclaration, AiDisclosure).
 */
export default function PublishingDeclarations({ bookId, data, setData, onNotify }) {
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
                    'That was not saved.',
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
                <h3 className="mb-1 text-[13px] font-semibold text-text-primary">Legal deposit</h3>
                <p className="text-[12px] leading-snug text-text-body">
                    {data.legal_deposit.message}{' '}
                    {data.legal_deposit.source && (
                        <a
                            href={data.legal_deposit.source}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-brand-primary hover:underline"
                        >
                            Source
                        </a>
                    )}
                </p>
                {!data.legal_deposit.country && (
                    <p className="mt-1 text-[11px] text-text-secondary">
                        Set the country of publication in the book's settings for the rule that applies.
                    </p>
                )}
            </section>
        </>
    );
}

const EDITION_LABELS = { ebook: 'eBook', paperback: 'Paperback', hardcover: 'Hardcover' };

function IsbnSources({ bookId, data, busy, send, editable }) {
    const agency = data.isbn_agency;

    return (
        <section
            data-a="rights:isbn-plan"
            className="rounded-tile border border-border-default bg-bg-surface p-3.5"
        >
            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">Where each ISBN comes from</h3>
            <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                A store-assigned ISBN works at that store only: KDP's free ISBN can only be used on
                KDP, and IngramSpark's free ISBN is for US publishers, at IngramSpark only.
            </p>
            <ul className="space-y-2">
                {Object.entries(data.isbn_plan).map(([edition, plan]) => (
                    <li key={edition}>
                        <fieldset className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <legend className="w-full text-[12px] font-medium text-text-primary sm:w-24">
                                {EDITION_LABELS[edition]}
                            </legend>
                            {[
                                ['own', 'My own ISBN'],
                                ['store', 'Assigned by the store'],
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
                                    No number assigned yet — add it above.
                                </span>
                            )}
                        </fieldset>
                    </li>
                ))}
            </ul>
            <p data-a="rights:agency" className="mt-2 text-[11px] leading-snug text-text-secondary">
                Get your own from{' '}
                <a href={agency.url} target="_blank" rel="noopener noreferrer" className="font-medium text-brand-primary hover:underline">
                    {agency.agency}
                </a>
                {agency.free === true && ', which gives them free to eligible publishers'}.
                {!agency.country && ' Set the country of publication in the book\'s settings to see your agency.'}
            </p>
        </section>
    );
}

function Declaration({ bookId, data, busy, send, editable }) {
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
            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">Rights declaration</h3>
            <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                Every store asks you to warrant that you hold the rights to what you upload. List the
                material in the book that is not yours — quotations, images, official papers — and what
                you hold for each. This records what you state; it is not legal advice.
            </p>

            {materials.length === 0 && (
                <p className="text-[11.5px] text-text-secondary">No third-party material listed.</p>
            )}

            <ul className="space-y-2">
                {materials.map((item, index) => (
                    <li key={index} className="grid grid-cols-1 gap-1.5 rounded-control border border-border-default p-2 sm:grid-cols-[150px_1fr_160px_auto]">
                        <select
                            aria-label="Kind of material"
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
                            aria-label="What it is and where it comes from"
                            value={item.description}
                            data-a={`rights:material:${index}:description`}
                            placeholder="e.g. Map, page 12 — from the national archive"
                            disabled={!editable}
                            onChange={(event) => update(index, 'description', event.target.value)}
                            className="rounded-control border border-border-input px-2 py-1.5 text-[12px] focus:border-brand-primary focus:ring-0"
                        />
                        <select
                            aria-label="What you hold"
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
                                Remove
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
                        Add material
                    </button>

                    <label className="mt-3 flex items-start gap-2 text-[12px] leading-snug text-text-body">
                        <input
                            type="checkbox"
                            checked={holds}
                            data-a="rights:declaration:holds"
                            onChange={(event) => setHolds(event.target.checked)}
                            className="mt-0.5"
                        />
                        I hold the rights to publish this book, and the list above is the third-party material
                        in it with what I hold for each.
                    </label>

                    <button
                        type="button"
                        data-a="rights:declaration:confirm"
                        disabled={!holds || busy !== null}
                        onClick={() =>
                            send(
                                'declaration',
                                () => window.axios.put(`/books/${bookId}/rights/declaration`, { holds_rights: true, materials }),
                                'Rights declaration confirmed.',
                            )
                        }
                        className="mt-2 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                    >
                        {declaration.confirmed && !changed ? 'Confirm again' : 'Confirm declaration'}
                    </button>
                </>
            )}

            <p data-a="rights:declaration:status" className="mt-2 text-[11px] text-text-secondary">
                {declaration.confirmed
                    ? `Confirmed by ${declaration.confirmed_by} on ${new Date(declaration.confirmed_at).toLocaleString()}.${changed ? ' You have unsaved changes: confirm again.' : ''}`
                    : 'Not confirmed yet. Prepare for stores needs it.'}
            </p>
        </section>
    );
}

const ANSWERS = {
    none: 'No AI',
    assisted: 'AI-assisted (I wrote it; AI edited or checked it)',
    generated: 'AI-generated (created by an AI tool)',
};

const CATEGORY_LABELS = { text: 'Text', images: 'Images (cover and interior)', translations: 'Translations' };

function AiQuestions({ bookId, data, busy, send, editable }) {
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
                `${evidence.text.ai_drafted_chapters} chapter${evidence.text.ai_drafted_chapters === 1 ? '' : 's'} drafted with AI in Écrivain (${evidence.text.ai_drafts} draft${evidence.text.ai_drafts === 1 ? '' : 's'}).`,
            evidence.text.accepted_ai_fixes > 0 && `${evidence.text.accepted_ai_fixes} AI editorial fix${evidence.text.accepted_ai_fixes === 1 ? '' : 'es'} accepted.`,
            evidence.text.ai_suggestions_applied > 0 && `${evidence.text.ai_suggestions_applied} inline AI suggestion${evidence.text.ai_suggestions_applied === 1 ? '' : 's'} applied.`,
        ].filter(Boolean),
        images: [
            evidence.images.ai_cover &&
                `The cover artwork was made with AI${evidence.images.ai_cover.model ? ` (${evidence.images.ai_cover.model})` : ''}.`,
        ].filter(Boolean),
        translations: [evidence.translations.translation_edition && 'This is a translation edition; no AI translation ran in Écrivain.'].filter(Boolean),
    };

    return (
        <section data-a="rights:ai" className="rounded-tile border border-border-default bg-bg-surface p-3.5">
            <h3 className="mb-1 text-[13px] font-semibold text-text-primary">AI content</h3>
            <p className="mb-2 text-[11px] leading-snug text-text-secondary">
                KDP asks whether text, images or translations are AI-generated — created by an AI tool —
                and does not ask about AI-assisted work you created and edited with AI. Below is what
                Écrivain recorded and the answer it supports. Work done with AI outside Écrivain is not
                recorded here: you decide.{' '}
                <a href={ai.source} target="_blank" rel="noopener noreferrer" className="font-medium text-brand-primary hover:underline">
                    KDP's guidelines
                </a>
            </p>

            <div className="space-y-3">
                {Object.keys(CATEGORY_LABELS).map((category) => (
                    <fieldset key={category} data-a={`rights:ai:${category}`}>
                        <legend className="text-[12px] font-medium text-text-primary">{CATEGORY_LABELS[category]}</legend>
                        <p className="text-[11px] text-text-secondary">
                            {lines[category].length ? lines[category].join(' ') : 'No AI use recorded.'} Suggested:{' '}
                            <span className="font-medium text-text-body">{ANSWERS[ai.suggested[category]]}</span>.
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
                                    {label}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                ))}
            </div>

            {ai.outdated.length > 0 && (
                <p role="status" className="mt-2 text-[11.5px] font-medium text-status-warning">
                    The records now show more AI use ({ai.outdated.join(', ')}) than your confirmed answers.
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
                        These answers are mine and true for the whole book, including work done outside Écrivain.
                    </label>
                    <button
                        type="button"
                        data-a="rights:ai:confirm"
                        disabled={!sure || busy !== null}
                        onClick={() =>
                            send(
                                'ai',
                                () => window.axios.put(`/books/${bookId}/rights/ai-disclosure`, { confirm: true, answers }),
                                'AI-content answers confirmed.',
                            )
                        }
                        className="mt-2 rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50"
                    >
                        Confirm answers
                    </button>
                </>
            )}

            <p data-a="rights:ai:status" className="mt-2 text-[11px] text-text-secondary">
                {ai.confirmed_at
                    ? `Confirmed by ${ai.confirmed_by} on ${new Date(ai.confirmed_at).toLocaleString()}. At KDP: text ${ai.kdp.text ? 'Yes' : 'No'}, images ${ai.kdp.images ? 'Yes' : 'No'}, translations ${ai.kdp.translations ? 'Yes' : 'No'} to "AI-generated".`
                    : ai.required
                      ? 'Not answered yet. Prepare for stores needs it for KDP.'
                      : 'Not answered yet. KDP asks this; other stores here do not.'}
            </p>
        </section>
    );
}
