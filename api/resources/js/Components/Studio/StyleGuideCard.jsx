import { useEffect, useState } from 'react';

/**
 * The book's style guide (ADR — Style guides and review options): the
 * editorial rules the Copy, Line and Consistency agents review against,
 * and the words and rules this book never flags.
 *
 * A book follows one of its account's guides, or keeps its own rules.
 * Editing a followed guide saves its next version (every book that follows
 * it reads it at its next review); "Customise for this book" stops
 * following and keeps the rules as the book's own.
 */

const control =
    'w-full min-w-0 rounded-control border border-border-input bg-bg-surface px-2 py-1.5 text-[12.5px] text-text-body focus:border-brand-primary focus:ring-0';
const smallButton =
    'rounded-control border border-border-input bg-bg-surface px-2.5 py-1.5 text-[12px] font-semibold text-text-body hover:border-brand-primary disabled:opacity-50';
const primaryButton =
    'rounded-control bg-brand-fill px-3 py-1.5 text-[12px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-50';

const TRI = [
    ['', 'Not set'],
    ['1', 'Yes'],
    ['0', 'No'],
];

const tri = (value) => (value === null || value === undefined ? '' : value ? '1' : '0');
const fromTri = (value) => (value === '' ? null : value === '1');
const lines = (list) => (list ?? []).join('\n');
const fromLines = (text) =>
    text
        .split(/\n|,/)
        .map((s) => s.trim())
        .filter(Boolean);

function messageOf(error) {
    const errors = error.response?.data?.errors;

    return errors ? Object.values(errors).flat().join(' ') : (error.response?.data?.message ?? 'That did not work.');
}

export default function StyleGuideCard({ bookId, onNotify, onChanged }) {
    const [style, setStyle] = useState(null);
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [busy, setBusy] = useState(false);
    const [name, setName] = useState('');
    const [pick, setPick] = useState('');

    const load = async () => {
        const { data } = await window.axios.get(`/books/${bookId}/style-guide`);
        setStyle(data.style);
    };

    useEffect(() => {
        load();
    }, [bookId]);

    if (!style) return null;

    const run = async (request, done) => {
        setBusy(true);

        try {
            const { data } = await request();
            if (data.style) setStyle(data.style);
            else await load();
            onNotify?.(done);
            onChanged?.();
            return true;
        } catch (error) {
            onNotify?.(messageOf(error));
            return false;
        } finally {
            setBusy(false);
        }
    };

    const following = style.following;
    const guides = style.guides ?? [];
    const chosen = pick || guides.find((g) => g.id !== following?.id)?.id || '';
    // What the form edits: the followed guide (its next version), or the
    // book's own rules.
    const target = following ? (style.can_manage ? 'guide' : null) : style.can_edit ? 'own' : null;

    const save = async () => {
        const ok = await run(
            () =>
                target === 'guide'
                    ? window.axios.put(`/style-guides/${following.id}`, { rules: editing, book: bookId })
                    : window.axios.put(`/books/${bookId}/style-guide/own`, { rules: editing }),
            target === 'guide' ? `Saved “${following.name}” as a new version.` : 'This book’s style rules are saved.',
        );
        if (ok) setEditing(null);
    };

    return (
        <section className="mb-5 rounded-panel border border-border-default bg-bg-surface p-4" data-a="style-guide">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="text-[13.5px] font-semibold text-text-primary">Style guide</h3>
                    <p className="mt-0.5 text-[12.5px] text-text-secondary" data-a="style-guide:status">
                        {following
                            ? `Follows “${following.name}”, version ${following.version}.`
                            : style.configured
                              ? 'This book has its own style rules.'
                              : 'No style guide. Copy, Line and Consistency review without one.'}
                    </p>
                </div>
                <button type="button" data-a="style-guide:toggle" aria-expanded={open} onClick={() => setOpen((o) => !o)} className={smallButton}>
                    {open ? 'Close' : following || style.configured ? 'Show rules' : 'Set up'}
                </button>
            </div>

            {open && (
                <div className="mt-3 space-y-4">
                    {style.can_manage && (
                        <div className="flex flex-wrap items-end gap-2">
                            {guides.filter((g) => g.id !== following?.id).length > 0 && (
                                <>
                                    <label className="min-w-[180px] flex-1 text-[11.5px] text-text-secondary">
                                        Follow a guide of this account
                                        <select data-a="style-guide:pick" value={chosen} onChange={(e) => setPick(Number(e.target.value))} className={`${control} mt-1`}>
                                            {guides
                                                .filter((g) => g.id !== following?.id)
                                                .map((g) => (
                                                    <option key={g.id} value={g.id}>
                                                        {g.name} (v{g.version}, {g.books} book{g.books === 1 ? '' : 's'})
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        data-a="style-guide:follow"
                                        disabled={busy || !chosen}
                                        onClick={() => run(() => window.axios.post(`/books/${bookId}/style-guide/follow`, { guide: chosen }), 'This book now follows the guide.')}
                                        className={smallButton}
                                    >
                                        Follow
                                    </button>
                                </>
                            )}
                            <label className="min-w-[180px] flex-1 text-[11.5px] text-text-secondary">
                                New guide from these rules
                                <input data-a="style-guide:name" value={name} maxLength={80} placeholder="e.g. House style" onChange={(e) => setName(e.target.value)} className={`${control} mt-1`} />
                            </label>
                            <button
                                type="button"
                                data-a="style-guide:create"
                                disabled={busy || name.trim().length < 2}
                                onClick={async () => {
                                    const ok = await run(() => window.axios.post(`/books/${bookId}/style-guide`, { name: name.trim(), rules: style.rules, follow: true }), `Created “${name.trim()}”. This book follows it.`);
                                    if (ok) setName('');
                                }}
                                className={primaryButton}
                            >
                                Create and follow
                            </button>
                        </div>
                    )}

                    {following && style.can_edit && (
                        <button
                            type="button"
                            data-a="style-guide:detach"
                            disabled={busy}
                            onClick={() => run(() => window.axios.post(`/books/${bookId}/style-guide/detach`), 'This book keeps the rules as its own and no longer follows the guide.')}
                            className={smallButton}
                        >
                            Customise for this book
                        </button>
                    )}

                    {editing ? (
                        <RulesForm rules={editing} setRules={setEditing} options={style.options} />
                    ) : (
                        <RulesSummary rules={style.rules} />
                    )}

                    {target && (
                        <div className="flex flex-wrap gap-2">
                            {editing ? (
                                <>
                                    <button type="button" data-a="style-guide:save" disabled={busy} onClick={save} className={primaryButton}>
                                        {target === 'guide' ? 'Save as new version' : 'Save this book’s rules'}
                                    </button>
                                    <button type="button" onClick={() => setEditing(null)} className={smallButton}>
                                        Cancel
                                    </button>
                                </>
                            ) : (
                                <button
                                    type="button"
                                    data-a="style-guide:edit"
                                    onClick={() => setEditing(structuredClone(target === 'guide' ? (guides.find((g) => g.id === following.id)?.rules ?? style.rules) : style.own))}
                                    className={smallButton}
                                >
                                    {target === 'guide' ? `Edit “${following.name}”` : 'Edit this book’s rules'}
                                </button>
                            )}
                        </div>
                    )}

                    <BookOwn style={style} bookId={bookId} run={run} busy={busy} />
                </div>
            )}
        </section>
    );
}

function RulesSummary({ rules }) {
    const items = [
        rules.language && ['Language', rules.language],
        rules.spelling?.length && ['Spelling', rules.spelling.join('; ')],
        rules.preferred_terms?.length && ['Preferred terms', rules.preferred_terms.map((t) => `${t.use} (not ${t.avoid})`).join(', ')],
        rules.banned?.length && ['Banned', rules.banned.map((t) => t.phrase).join(', ')],
        rules.dictionary?.length && ['Never flagged', `${rules.dictionary.length} word${rules.dictionary.length === 1 ? '' : 's'}`],
        rules.numbers?.spell_out_below && ['Numbers', `spell out under ${rules.numbers.spell_out_below}${rules.numbers.numerals_for_measurements ? ', numerals for measurements' : ''}`],
        rules.serial_comma !== null && ['Serial comma', rules.serial_comma ? 'yes' : 'no'],
        rules.quotes && ['Quotes', rules.quotes],
        rules.french_spacing !== null && ['French spacing', rules.french_spacing ? 'space before ; : ! ?' : 'none'],
        rules.dashes && ['Dashes', rules.dashes.replace('_', ' ')],
        rules.heading_case && ['Headings', rules.heading_case],
        rules.date_format && ['Dates', rules.date_format],
        rules.notes && ['Notes', rules.notes],
    ].filter(Boolean);

    if (items.length === 0) {
        return <p className="text-[12.5px] text-text-secondary">No rules yet.</p>;
    }

    return (
        <dl className="grid grid-cols-1 gap-x-4 gap-y-1 text-[12.5px] sm:grid-cols-[150px_1fr]" data-a="style-guide:summary">
            {items.map(([term, value]) => (
                <div key={term} className="contents">
                    <dt className="font-medium text-text-primary">{term}</dt>
                    <dd className="min-w-0 break-words text-text-secondary">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function Select({ label, value, onChange, choices, a }) {
    return (
        <label className="block text-[11.5px] text-text-secondary">
            {label}
            <select data-a={a} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)} className={`${control} mt-1`}>
                <option value="">Not set</option>
                {choices.map(([v, l]) => (
                    <option key={v} value={v}>
                        {l}
                    </option>
                ))}
            </select>
        </label>
    );
}

function TriSelect({ label, value, onChange, a }) {
    return (
        <label className="block text-[11.5px] text-text-secondary">
            {label}
            <select data-a={a} value={tri(value)} onChange={(e) => onChange(fromTri(e.target.value))} className={`${control} mt-1`}>
                {TRI.map(([v, l]) => (
                    <option key={v} value={v}>
                        {l}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Pairs({ label, rows, fields, onChange, a, max }) {
    return (
        <fieldset className="min-w-0">
            <legend className="mb-1 text-[11.5px] text-text-secondary">{label}</legend>
            <div className="space-y-1.5">
                {rows.map((row, i) => (
                    <div key={i} className="flex min-w-0 flex-wrap gap-1.5 sm:flex-nowrap">
                        {fields.map(([key, placeholder]) => (
                            <input
                                key={key}
                                data-a={`${a}:${i}:${key}`}
                                value={row[key] ?? ''}
                                placeholder={placeholder}
                                maxLength={key === 'note' ? 200 : 80}
                                onChange={(e) => onChange(rows.map((r, j) => (j === i ? { ...r, [key]: e.target.value } : r)))}
                                className={`${control} sm:flex-1`}
                            />
                        ))}
                        <button type="button" aria-label="Remove" onClick={() => onChange(rows.filter((_, j) => j !== i))} className={smallButton}>
                            ×
                        </button>
                    </div>
                ))}
            </div>
            {rows.length < max && (
                <button type="button" data-a={`${a}:add`} onClick={() => onChange([...rows, Object.fromEntries(fields.map(([k]) => [k, '']))])} className={`${smallButton} mt-1.5`}>
                    Add
                </button>
            )}
        </fieldset>
    );
}

function RulesForm({ rules, setRules, options }) {
    const set = (key, value) => setRules((r) => ({ ...r, [key]: value }));
    const limits = options.limits ?? {};

    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" data-a="style-guide:form">
            <Select label="Language variety" a="style-guide:language" value={rules.language} onChange={(v) => set('language', v)} choices={options.languages} />
            <Select label="Quotation marks" a="style-guide:quotes" value={rules.quotes} onChange={(v) => set('quotes', v)} choices={options.quotes} />
            <TriSelect label="Serial (Oxford) comma" a="style-guide:serial" value={rules.serial_comma} onChange={(v) => set('serial_comma', v)} />
            <TriSelect label="French spacing (space before ; : ! ?)" a="style-guide:french" value={rules.french_spacing} onChange={(v) => set('french_spacing', v)} />
            <Select label="Dashes" a="style-guide:dashes" value={rules.dashes} onChange={(v) => set('dashes', v)} choices={options.dashes} />
            <Select label="Heading capitalisation" a="style-guide:headings" value={rules.heading_case} onChange={(v) => set('heading_case', v)} choices={options.heading_case} />
            <Select label="Dates" a="style-guide:dates" value={rules.date_format} onChange={(v) => set('date_format', v)} choices={options.date_format} />
            <label className="block text-[11.5px] text-text-secondary">
                Numbers
                <select
                    data-a="style-guide:numbers"
                    value={rules.numbers?.spell_out_below ?? ''}
                    onChange={(e) => set('numbers', { ...rules.numbers, spell_out_below: e.target.value === '' ? null : Number(e.target.value) })}
                    className={`${control} mt-1`}
                >
                    <option value="">Not set</option>
                    {options.spell_out.map((n) => (
                        <option key={n} value={n}>
                            Spell out under {n}
                        </option>
                    ))}
                </select>
                <span className="mt-1 flex items-center gap-1.5 text-text-body">
                    <input
                        type="checkbox"
                        checked={Boolean(rules.numbers?.numerals_for_measurements)}
                        onChange={(e) => set('numbers', { ...rules.numbers, numerals_for_measurements: e.target.checked || null })}
                    />
                    Numerals for measurements
                </span>
            </label>
            <div className="sm:col-span-2">
                <Pairs label="Preferred terms (use … not …)" a="style-guide:preferred" rows={rules.preferred_terms ?? []} max={limits.terms ?? 200} fields={[['use', 'Use'], ['avoid', 'Not'], ['note', 'Note (optional)']]} onChange={(v) => set('preferred_terms', v)} />
            </div>
            <div className="sm:col-span-2">
                <Pairs label="Banned words and phrases" a="style-guide:banned" rows={rules.banned ?? []} max={limits.terms ?? 200} fields={[['phrase', 'Word or phrase'], ['note', 'Why (optional)']]} onChange={(v) => set('banned', v)} />
            </div>
            <label className="block text-[11.5px] text-text-secondary">
                Spelling preferences (one per line)
                <textarea data-a="style-guide:spelling" rows={3} value={lines(rules.spelling)} onChange={(e) => set('spelling', e.target.value.split('\n'))} onBlur={(e) => set('spelling', e.target.value.split('\n').map((s) => s.trim()).filter(Boolean))} className={`${control} mt-1`} />
            </label>
            <label className="block text-[11.5px] text-text-secondary">
                Never flag these words (names, Kreyòl terms; one per line)
                <textarea data-a="style-guide:dictionary" rows={3} value={lines(rules.dictionary)} onChange={(e) => set('dictionary', e.target.value.split('\n'))} onBlur={(e) => set('dictionary', fromLines(e.target.value))} className={`${control} mt-1`} />
            </label>
            <label className="block text-[11.5px] text-text-secondary sm:col-span-2">
                Notes for the editors ({(rules.notes ?? '').length}/{limits.notes ?? 2000})
                <textarea data-a="style-guide:notes" rows={3} maxLength={limits.notes ?? 2000} value={rules.notes ?? ''} onChange={(e) => set('notes', e.target.value || null)} className={`${control} mt-1`} />
            </label>
        </div>
    );
}

/** The words and rules this book never flags, each one undoable here. */
function BookOwn({ style, bookId, run, busy }) {
    const words = style.own?.dictionary ?? [];
    const rules = style.disabled_rules ?? [];

    if (words.length === 0 && rules.length === 0) return null;

    return (
        <div className="border-t border-border-default pt-3" data-a="style-guide:own">
            <p className="mb-1.5 text-[11.5px] font-medium uppercase tracking-wide text-text-secondary">Never flagged in this book</p>
            <div className="flex flex-wrap gap-1.5">
                {words.map((word) => (
                    <span key={`w-${word}`} className="inline-flex items-center gap-1 rounded-badge bg-bg-subtle px-2 py-1 text-[12px] text-text-body">
                        {word}
                        {style.can_edit && (
                            <button
                                type="button"
                                aria-label={`Flag “${word}” again`}
                                disabled={busy}
                                onClick={() => run(() => window.axios.post(`/books/${bookId}/findings/unsuppress`, { mode: 'word', value: word }), `“${word}” can be flagged again.`)}
                                className="text-text-secondary hover:text-text-primary"
                            >
                                ×
                            </button>
                        )}
                    </span>
                ))}
                {rules.map((rule) => (
                    <span key={`r-${rule}`} className="inline-flex items-center gap-1 rounded-badge border border-dashed border-border-default px-2 py-1 text-[12px] text-text-body">
                        Rule off: {rule}
                        {style.can_edit && (
                            <button
                                type="button"
                                aria-label={`Turn ${rule} back on`}
                                disabled={busy}
                                onClick={() => run(() => window.axios.post(`/books/${bookId}/findings/unsuppress`, { mode: 'rule', value: rule }), 'That rule is back on.')}
                                className="text-text-secondary hover:text-text-primary"
                            >
                                ×
                            </button>
                        )}
                    </span>
                ))}
            </div>
        </div>
    );
}
