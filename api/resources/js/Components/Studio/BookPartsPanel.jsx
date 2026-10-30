import { router } from "@inertiajs/react";
import { useId, useState } from "react";
import { t, useI18n } from "../../i18n";

/**
 * Book parts: the conventional parts of a book (The Chicago Manual of
 * Style, ch. 1), which this book has, and which its kind of book usually
 * carries.
 *
 * Adds a missing part from a template in the book's language, and puts the
 * binder in the conventional order — previewed first, applied only on
 * confirmation, and refused if the binder changed in between.
 */

const GROUPS = [
    { key: "front", label: "Front matter", tKey: 'parts.group_front' },
    { key: "body", label: "Text", tKey: 'parts.group_body' },
    { key: "back", label: "Back matter", tKey: 'parts.group_back' },
    { key: "cover", label: "Cover", tKey: 'parts.group_cover' },
];

const STATES = {
    present: {
        label: "Included",
        tKey: 'parts.state_present',
        tone: "bg-status-success-bg text-status-success",
    },
    generated: {
        label: "Generated",
        tKey: 'parts.state_generated',
        tone: "bg-brand-primary/10 text-brand-primary",
    },
    empty: {
        label: "Empty",
        tKey: 'parts.state_empty',
        tone: "bg-status-warning-bg text-status-warning",
    },
    placeholder: {
        label: "Template not filled in",
        tKey: 'parts.state_placeholder',
        tone: "bg-status-warning-bg text-status-warning",
    },
    missing: {
        label: "Not included",
        tKey: 'parts.state_missing',
        tone: "bg-bg-subtle text-text-secondary",
    },
    unavailable: {
        label: "Nothing to generate",
        tKey: 'parts.state_unavailable',
        tone: "bg-bg-subtle text-text-secondary",
    },
};

const PROFILES = {
    fiction: { tKey: 'parts.profile_fiction' },
    nonfiction: { tKey: 'parts.profile_nonfiction' },
    educational: { tKey: 'parts.profile_educational' },
    academic: { tKey: 'parts.profile_academic' },
};

export default function BookPartsPanel({ bookId, initial }) {
    useI18n();
    const [checklist, setChecklist] = useState(initial);
    const [busy, setBusy] = useState(null);
    const [message, setMessage] = useState("");
    const [preview, setPreview] = useState(null);
    const [showAll, setShowAll] = useState(false);
    const headingId = useId();

    const fail = (error) =>
        setMessage(
            error?.response?.data?.message ?? t('parts.failed'),
        );

    const add = (row) => {
        setBusy(`add:${row.key}`);
        setMessage("");

        window.axios
            .post(route("books.parts.store", bookId), { part: row.key })
            .then(({ data }) => {
                setChecklist(data.checklist);
                setMessage(t('parts.added', { title: data.section.title }));
                router.reload({ only: ["copyright_preview"] });
            })
            .catch(fail)
            .finally(() => setBusy(null));
    };

    const askOrder = () => {
        setBusy("preview");
        setMessage("");

        window.axios
            .get(route("books.parts.order", bookId))
            .then(({ data }) => setPreview(data))
            .catch(fail)
            .finally(() => setBusy(null));
    };

    const applyOrder = () => {
        setBusy("reorder");

        window.axios
            .post(route("books.parts.reorder", bookId), {
                fingerprint: preview.fingerprint,
            })
            .then(({ data }) => {
                setChecklist(data);
                setPreview(null);
                setMessage(t('parts.reordered'));
            })
            .catch((error) => {
                if (error?.response?.status === 409) {
                    // Show what would happen now, rather than applying an
                    // order the author never saw.
                    setPreview(error.response.data.preview);
                    setMessage(error.response.data.message);

                    return;
                }

                fail(error);
            })
            .finally(() => setBusy(null));
    };

    const rows = checklist.parts.filter(
        (row) =>
            showAll ||
            row.recommended ||
            row.state !== "missing" ||
            row.key === "chapter",
    );

    return (
        <section
            aria-labelledby={headingId}
            className="mt-5 rounded-panel border border-border-default bg-bg-surface p-6"
            data-a="parts:panel"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2
                        id={headingId}
                        className="text-[15px] font-semibold text-text-primary"
                    >
                        {t('parts.title')}
                    </h2>
                    <p className="mt-1 max-w-[520px] text-[12.5px] leading-relaxed text-text-secondary">
                        {t('parts.intro', {
                            profile: PROFILES[checklist.profile]
                                ? t(PROFILES[checklist.profile].tKey)
                                : checklist.profile,
                            status:
                                checklist.missing_recommended === 0
                                    ? t('parts.all_present')
                                    : t('parts.still_to_add', {
                                          count: checklist.missing_recommended,
                                      }),
                        })}
                    </p>
                </div>

                <button
                    type="button"
                    data-a="parts:order:preview"
                    onClick={askOrder}
                    disabled={busy !== null || checklist.in_order}
                    className="h-[34px] shrink-0 rounded-control border border-border-input bg-bg-surface px-3.5 text-[13px] font-medium text-text-body transition hover:bg-bg-subtle disabled:opacity-60"
                >
                    {checklist.in_order
                        ? t('parts.in_order')
                        : t('parts.put_in_order')}
                </button>
            </div>

            <p
                role="status"
                aria-live="polite"
                className="mt-3 min-h-[1em] text-[12.5px] text-text-secondary"
            >
                {message}
            </p>

            {preview && (
                <OrderPreview
                    preview={preview}
                    busy={busy === "reorder"}
                    onApply={applyOrder}
                    onCancel={() => setPreview(null)}
                />
            )}

            {GROUPS.map((group) => {
                const list = rows.filter((row) => row.group === group.key);

                if (list.length === 0) return null;

                return (
                    <div key={group.key} className="mt-4">
                        <h3 className="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">
                            {t(group.tKey)}
                        </h3>
                        <ul className="mt-1 divide-y divide-border-divider">
                            {list.map((row) => (
                                <PartRow
                                    key={row.key}
                                    row={row}
                                    adding={busy === `add:${row.key}`}
                                    disabled={busy !== null}
                                    onAdd={() => add(row)}
                                />
                            ))}
                        </ul>
                    </div>
                );
            })}

            <button
                type="button"
                data-a="parts:show-all"
                aria-expanded={showAll}
                onClick={() => setShowAll((value) => !value)}
                className="mt-4 text-[12.5px] font-medium text-brand-primary hover:underline"
            >
                {showAll
                    ? t('parts.show_used')
                    : t('parts.show_all')}
            </button>

            {checklist.unclassified.length > 0 && (
                <div className="mt-4 rounded-control bg-bg-subtle px-3.5 py-3">
                    <p className="text-[12.5px] font-medium text-text-primary">
                        {t('parts.unclassified_title')}
                    </p>
                    <p className="mt-0.5 text-[12px] text-text-secondary">
                        {t('parts.unclassified_hint')}
                    </p>
                    <ul className="mt-1.5 list-disc pl-5 text-[12.5px] text-text-body">
                        {checklist.unclassified.map((section) => (
                            <li key={section.id}>{section.title}</li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

function PartRow({ row, adding, disabled, onAdd }) {
    const state = STATES[row.state] ?? STATES.missing;
    const showAdd =
        row.can_add && (row.state === "missing" || row.key === "appendix");

    return (
        <li
            className="flex items-center justify-between gap-4 py-2.5"
            data-a={`parts:row:${row.key}`}
        >
            <div className="min-w-0">
                <p className="text-[13.5px] font-medium text-text-primary">
                    {row.name}
                    {row.name !== row.english && (
                        <span className="ml-1.5 text-[12px] font-normal text-text-secondary">
                            {row.english}
                        </span>
                    )}
                    {row.count > 1 && (
                        <span className="ml-1.5 text-[12px] font-normal text-text-secondary">
                            ×{row.count}
                        </span>
                    )}
                </p>
                <p className="mt-0.5 text-[11.5px] text-text-secondary">
                    {row.recommended && (
                        <span className="font-medium text-text-primary">
                            {row.alternatives.length > 0
                                ? t('parts.recommended_alt')
                                : t('parts.recommended')}
                            {" · "}
                        </span>
                    )}
                    {row.source === "generated"
                        ? t('parts.source_generated')
                        : t('parts.source_author')}
                </p>
                {row.issues.map((issue) => (
                    <p
                        key={issue}
                        className="mt-0.5 text-[11.5px] text-status-warning"
                    >
                        {issue}
                    </p>
                ))}
            </div>

            <div className="flex shrink-0 items-center gap-2">
                <span
                    className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${state.tone}`}
                >
                    {t(state.tKey)}
                </span>
                {showAdd && (
                    <button
                        type="button"
                        data-a={`parts:add:${row.key}`}
                        onClick={onAdd}
                        disabled={disabled}
                        aria-label={t('parts.add_label', { name: row.english })}
                        className="h-[30px] rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-60"
                    >
                        {adding ? t('parts.adding') : t('parts.add')}
                    </button>
                )}
            </div>
        </li>
    );
}

function OrderPreview({ preview, busy, onApply, onCancel }) {
    const titleId = useId();

    return (
        <div
            role="region"
            aria-labelledby={titleId}
            className="mt-3 rounded-control border border-brand-primary/30 bg-brand-primary/5 p-4"
            data-a="parts:order:dialog"
        >
            <h3
                id={titleId}
                className="text-[13.5px] font-semibold text-text-primary"
            >
                {t('parts.preview_title')}
            </h3>

            {preview.moves.length === 0 ? (
                <p className="mt-1 text-[12.5px] text-text-secondary">
                    {t('parts.nothing_moves')}
                </p>
            ) : (
                <>
                    <p className="mt-1 text-[12.5px] text-text-secondary">
                        {t('parts.moves', { count: preview.moves.length })}
                    </p>
                    <ol className="mt-2 list-decimal space-y-0.5 pl-5 text-[12.5px] text-text-body">
                        {preview.order.map((row) => (
                            <li key={row.id}>
                                {row.title}
                                {row.from !== row.to && (
                                    <span className="ml-1.5 text-text-secondary">
                                        {t('parts.was', { position: row.from })}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ol>
                </>
            )}

            <div className="mt-3 flex gap-2">
                <button
                    type="button"
                    data-a="parts:order:cancel"
                    onClick={onCancel}
                    className="h-[32px] rounded-control border border-border-input bg-bg-surface px-3 text-[12.5px] font-medium text-text-body"
                >
                    {t('common.cancel')}
                </button>
                {preview.moves.length > 0 && (
                    <button
                        type="button"
                        data-a="parts:order:apply"
                        onClick={onApply}
                        disabled={busy}
                        className="h-[32px] rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-60"
                    >
                        {busy ? t('parts.reordering') : t('parts.reorder')}
                    </button>
                )}
            </div>
        </div>
    );
}
