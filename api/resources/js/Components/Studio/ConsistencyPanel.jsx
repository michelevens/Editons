import { useEffect, useState } from 'react';
import { useI18n } from '@/i18n';

/**
 * Characters, terms and their agreed spellings — plus the cross-chapter scan
 * that uses them.
 *
 * The vocabulary is declared by the author, never inferred. The empty state
 * says so, because a reader who expects the software to work it out would
 * otherwise read "no issues" as "no inconsistencies exist".
 */
export default function ConsistencyPanel({ bookId, onOpenSection }) {
    const { t } = useI18n();
    const [terms, setTerms] = useState([]);
    const [issues, setIssues] = useState([]);
    const [loading, setLoading] = useState(true);
    const [adding, setAdding] = useState(false);

    const load = async () => {
        setLoading(true);
        try {
            const res = await window.axios.get(`/books/${bookId}/consistency`);
            setTerms(res.data.terms);
            setIssues(res.data.issues);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, [bookId]);

    const remove = async (term) => {
        if (!confirm(t('consistency.remove_confirm', { name: term.canonical }))) return;
        const res = await window.axios.delete(`/terms/${term.id}`);
        setTerms(res.data.terms);
        setIssues(res.data.issues);
    };

    return (
        <div className="mx-auto max-w-4xl p-8">
            <header className="mb-6 flex items-baseline justify-between">
                <div>
                    <h2 className="font-serif text-2xl font-bold text-text-primary">
                        {t('side.terms')}
                    </h2>
                    <p className="mt-1 text-sm text-text-secondary">
                        {t('consistency.intro')}
                    </p>
                </div>
                <button
                    onClick={() => setAdding((v) => !v)}
                    className="rounded-md bg-brand-fill px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-primary-hover"
                >
                    {adding ? t('common.cancel') : t('consistency.add_term')}
                </button>
            </header>

            {adding && (
                <AddTerm
                    bookId={bookId}
                    onAdded={(data) => {
                        setTerms(data.terms);
                        setIssues(data.issues);
                        setAdding(false);
                    }}
                />
            )}

            <section className="mb-8">
                <h3 className="mb-2 text-xs font-bold uppercase tracking-wider text-text-secondary">
                    {t('consistency.inconsistencies')}
                </h3>

                {loading ? (
                    <p className="text-sm text-text-secondary">{t('consistency.scanning')}</p>
                ) : terms.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border-input p-6 text-center text-sm leading-relaxed text-text-secondary">
                        {t('consistency.empty')}
                    </p>
                ) : issues.length === 0 ? (
                    <p className="rounded-lg border border-status-success/30 bg-status-success-bg p-4 text-sm text-status-success">
                        {t('consistency.no_disagreements', { count: terms.length })}
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {issues.map((issue, i) => (
                            <li
                                key={`${issue.term_id}-${issue.variant}-${i}`}
                                className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-4"
                            >
                                <p className="text-sm text-status-warning">
                                    <span className="font-bold">
                                        {t('consistency.quoted', { text: issue.variant })}
                                    </span>{' '}
                                    {t('consistency.issue_appears', { count: issue.total })}{' '}
                                    <span className="font-bold">
                                        {t('consistency.quoted', { text: issue.canonical })}
                                    </span>
                                    .
                                </p>

                                <ul className="mt-2 flex flex-wrap gap-2">
                                    {issue.sections.map((s) => (
                                        <li key={s.section_id}>
                                            <button
                                                onClick={() =>
                                                    onOpenSection?.(s.section_id)
                                                }
                                                className="rounded bg-bg-surface px-2 py-1 text-xs font-semibold text-status-warning ring-1 ring-status-warning/30 hover:bg-status-warning-tile"
                                            >
                                                {s.section_title} ×
                                                {s.occurrences}
                                            </button>
                                        </li>
                                    ))}
                                </ul>

                                {issue.note && (
                                    <p className="mt-2 text-xs italic text-status-warning">
                                        {issue.note}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section>
                <h3 className="mb-2 text-xs font-bold uppercase tracking-wider text-text-secondary">
                    {t('consistency.declared')}
                </h3>

                {terms.length === 0 ? (
                    <p className="text-sm text-text-secondary">{t('consistency.nothing_declared')}</p>
                ) : (
                    <ul className="divide-y divide-border-divider rounded-lg border border-border-default bg-bg-surface">
                        {terms.map((term) => (
                            <li
                                key={term.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="w-24 shrink-0 text-[10px] font-bold uppercase tracking-wide text-text-secondary">
                                    {{
                                        character: t('consistency.badge_character'),
                                        place: t('consistency.badge_place'),
                                        term: t('consistency.badge_term'),
                                        organization: t('consistency.badge_organization'),
                                        other: t('consistency.badge_other'),
                                    }[term.kind] ?? term.kind}
                                </span>
                                <span className="font-semibold text-text-primary">
                                    {term.canonical}
                                </span>
                                {term.variants?.length > 0 && (
                                    <span className="text-sm text-text-secondary">
                                        {t('consistency.not_variants', { variants: term.variants.join(', ') })}
                                    </span>
                                )}
                                <button
                                    onClick={() => remove(term)}
                                    className="ml-auto text-xs font-semibold text-text-secondary hover:text-status-danger"
                                >
                                    {t('consistency.remove')}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

function AddTerm({ bookId, onAdded }) {
    const { t } = useI18n();
    const [kind, setKind] = useState('character');
    const [canonical, setCanonical] = useState('');
    const [variants, setVariants] = useState('');
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);

        try {
            const res = await window.axios.post(`/books/${bookId}/terms`, {
                kind,
                canonical,
                variants: variants
                    .split(',')
                    .map((v) => v.trim())
                    .filter(Boolean),
                note: note || null,
            });
            onAdded(res.data);
        } finally {
            setSaving(false);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="mb-6 grid grid-cols-3 gap-3 rounded-lg border border-border-default bg-bg-subtle p-4"
        >
            <label className="text-xs font-semibold text-text-secondary">
                {t('consistency.kind_label')}
                <select
                    value={kind}
                    onChange={(e) => setKind(e.target.value)}
                    className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                >
                    <option value="character">{t('consistency.kind_character')}</option>
                    <option value="place">{t('consistency.kind_place')}</option>
                    <option value="term">{t('consistency.kind_term')}</option>
                    <option value="organization">{t('consistency.kind_organization')}</option>
                    <option value="other">{t('consistency.kind_other')}</option>
                </select>
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                {t('consistency.correct_spelling')}
                <input
                    value={canonical}
                    onChange={(e) => setCanonical(e.target.value)}
                    required
                    className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                />
            </label>

            <label className="text-xs font-semibold text-text-secondary">
                {t('consistency.flag_these')}
                <input
                    value={variants}
                    onChange={(e) => setVariants(e.target.value)}
                    placeholder="Jon, Johnny"
                    className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                />
            </label>

            <label className="col-span-3 text-xs font-semibold text-text-secondary">
                {t('consistency.note')}
                <input
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    className="w-full rounded-md border-border-input text-sm focus:border-brand-primary focus:ring-brand-primary"
                />
            </label>

            <div className="col-span-3">
                <button
                    disabled={saving}
                    className="rounded-md bg-bg-inverse px-4 py-1.5 text-sm font-bold text-text-inverse hover:opacity-90 disabled:opacity-60"
                >
                    {saving ? t('editor.save_saving') : t('consistency.add_term')}
                </button>
            </div>
        </form>
    );
}
