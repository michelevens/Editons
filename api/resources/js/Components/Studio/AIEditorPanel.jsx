import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import CitationsTab from './CitationsTab';
import CommentsPanel from './CommentsPanel';
import Icon from './Icon';

/**
 * The right rail: AI Editor, Comments, Research, Checks.
 *
 * HANDOFF.md component 6. The overview tiles and agent badges are computed
 * from real findings — never from a fixture — so an empty book shows zeros
 * rather than the mockup's sample numbers.
 */

/** Which glyph and tint each agent uses, keyed by the backend enum value. */
const AGENT_STYLE = {
    developmental: { icon: 'aDev', tile: 'bg-status-danger-tile text-status-danger' },
    fact_checker: { icon: 'aFact', tile: 'bg-status-warning-tile text-status-warning' },
    line: { icon: 'aLine', tile: 'bg-status-info-bg text-status-info' },
    copy: { icon: 'aCopy', tile: 'bg-status-success-tile text-status-success' },
    consistency: { icon: 'aCons', tile: 'bg-status-warning-tile text-status-warning' },
    subject_matter: { icon: 'aSubj', tile: 'bg-status-danger-tile text-status-danger' },
    accessibility: { icon: 'aAcc', tile: 'bg-status-success-tile text-status-success' },
    masters_examiner: { icon: 'cap', tile: 'bg-status-info-bg text-status-info' },
    doctoral_examiner: { icon: 'cap', tile: 'bg-status-danger-tile text-status-danger' },
};

const SEVERITY_BADGE = {
    critical: 'bg-status-danger-fill text-white',
    warning: 'bg-status-warning-badge text-white',
    suggestion: 'bg-status-info-fill text-white',
};

export default function AIEditorPanel({
    agents = [],
    findings = [],
    versions = [],
    running,
    reviewProgress,
    sectionId,
    bookId,
    onRunAgent,
    onRunFullReview,
    onResolve,
    onShowInText,
    onOpenSection,
    onRestore,
    onOpenLibrary,
}) {
    const [tab, setTab] = useState('AI Editor');
    const [openAgent, setOpenAgent] = useState(null);
    const [commentCount, setCommentCount] = useState(0);

    // The whole-book review lives here rather than on the Editor page: it is
    // the only consumer of this state, and bookId is already to hand.
    const [directing, setDirecting] = useState(false);
    const [direction, setDirection] = useState(null);

    const direct = async () => {
        if (!bookId || directing) return;

        setDirecting(true);

        try {
            const { data } = await window.axios.get(`/books/${bookId}/direct`);
            setDirection(data);
        } catch {
            setDirection({ findings: [], enough_to_review: true });
        } finally {
            setDirecting(false);
        }
    };

    const open = findings.filter((f) => f.status === 'open');

    const tabs = [
        { key: 'AI Editor', ready: true },
        { key: 'Comments', ready: true, count: commentCount },
        { key: 'Research', ready: true },
        { key: 'Checks', ready: true },
    ];

    return (
        <aside className="flex min-h-0 w-full flex-col overflow-hidden rounded-panel border border-border-default bg-bg-surface">
            <div className="flex shrink-0 border-b border-border-default" role="tablist">
                {tabs.map((t) => {
                    const isActive = tab === t.key;

                    return (
                        <button
                            key={t.key}
                            role="tab"
                            aria-selected={isActive}
                            data-a={`ai:tab:${t.key}`}
                            onClick={() => {
                                setTab(t.key);
                                setOpenAgent(null);
                            }}
                            title={t.ready ? undefined : 'Not built yet'}
                            className={`relative flex-1 py-[13px] text-[12.5px] transition ${
                                isActive
                                    ? 'font-semibold text-brand-primary'
                                    : t.ready
                                      ? 'text-text-secondary hover:text-text-primary'
                                      : 'text-text-secondary'
                            }`}
                        >
                            {t.key}
                            {t.count > 0 && ` (${t.count})`}
                            {!t.ready && (
                                <span className="ml-0.5 align-super text-[8px]">•</span>
                            )}
                            {isActive && (
                                <span className="absolute inset-x-0 -bottom-px h-[2px] bg-brand-fill" />
                            )}
                        </button>
                    );
                })}
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {tab === 'AI Editor' &&
                    (openAgent ? (
                        <AgentFindings
                            agent={agents.find((a) => a.value === openAgent)}
                            findings={findings.filter((f) => f.agent === openAgent)}
                            onBack={() => setOpenAgent(null)}
                            onResolve={onResolve}
                            onShowInText={onShowInText}
                        />
                    ) : (
                        <AgentList
                            agents={agents}
                            open={open}
                            running={running}
                            reviewProgress={reviewProgress}
                            onRunAgent={onRunAgent}
                            onRunFullReview={onRunFullReview}
                            onOpenAgent={setOpenAgent}
                            onDirect={direct}
                            directing={directing}
                            direction={direction}
                        />
                    ))}

                {tab === 'Research' && (
                    <CitationsTab
                        sectionId={sectionId}
                        bookId={bookId}
                        onManageLibrary={onOpenLibrary}
                    />
                )}

                {tab === 'Checks' && (
                    <ChecksTab findings={open} versions={versions} onRestore={onRestore} />
                )}

                {tab === 'Comments' && (
                    <CommentsPanel
                        bookId={bookId}
                        sectionId={sectionId}
                        onOpenSection={onOpenSection}
                        onChanged={(counts) => setCommentCount(counts.open)}
                    />
                )}
            </div>
        </aside>
    );
}

function AgentList({
    agents,
    open,
    running,
    reviewProgress,
    onRunAgent,
    onRunFullReview,
    onOpenAgent,
    onDirect,
    directing,
    direction,
}) {
    // The mockup's four tiles, each counted from real findings.
    const tiles = [
        {
            label: 'Claims to verify',
            value: open.filter((f) => f.agent === 'fact_checker').length,
            icon: 'shieldDot',
            tint: 'bg-status-danger-bg text-status-danger',
        },
        {
            label: 'Style suggestions',
            value: open.filter((f) => f.agent === 'line' || f.agent === 'copy').length,
            icon: 'info',
            tint: 'bg-status-info-bg text-status-info',
        },
        {
            label: 'Potential repetition',
            value: open.filter((f) => f.agent === 'consistency').length,
            icon: 'hex',
            tint: 'bg-status-warning-bg text-status-warning',
        },
        {
            label: 'Critical issues',
            value: open.filter((f) => f.severity === 'critical').length,
            icon: 'eye',
            tint: 'bg-status-success-bg text-status-success',
        },
    ];

    return (
        <div className="p-3.5">
            {/*
             * Two buttons, because there are two different reviews and this
             * was one button claiming to be both.
             *
             * "Run Full Editorial Review" ran seven agents over the one open
             * chapter and finished by saying "nothing open on this chapter".
             * The name promised the book; the code did a chapter. It is now
             * named for what it does, and the whole-book review sits beside
             * it doing what the old name promised.
             */}
            <button
                data-a="ai:run-full"
                onClick={onRunFullReview}
                disabled={Boolean(reviewProgress) || directing}
                className="flex h-[38px] w-full items-center justify-center gap-2 rounded-control bg-gradient-to-r from-ai-gradient-from to-ai-gradient-to text-[13px] font-semibold text-white shadow-floatingButton transition hover:opacity-95 disabled:opacity-80"
            >
                {reviewProgress ? (
                    <>
                        <Spinner />
                        {reviewProgress.label}
                    </>
                ) : (
                    <>
                        <Icon name="heart" size={16} />
                        Review this chapter
                    </>
                )}
            </button>

            <button
                data-a="ai:direct"
                onClick={onDirect}
                disabled={Boolean(reviewProgress) || directing}
                title="Structure, pacing and balance across every chapter"
                className="mt-2 flex h-[34px] w-full items-center justify-center gap-2 rounded-control border border-border-default text-[12.5px] font-semibold text-text-secondary transition hover:border-brand-primary/40 hover:text-text-primary disabled:opacity-60"
            >
                {directing ? (
                    <>
                        <Spinner dark />
                        Reading the whole book…
                    </>
                ) : (
                    'Review the whole book'
                )}
            </button>

            {direction && (
                <div className="mt-3 rounded-tile border border-border-default bg-bg-surface p-3">
                    <p className="text-[12px] font-semibold text-text-primary">
                        The whole book
                    </p>

                    {direction.findings.length === 0 ? (
                        <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                            {direction.enough_to_review
                                ? 'Nothing structural to raise. Shape, pacing and balance all read evenly.'
                                : 'Not enough written yet to say anything about the book’s shape. Three chapters with words in them is the point this becomes useful.'}
                        </p>
                    ) : (
                        <ul className="mt-1.5 divide-y divide-border-divider">
                            {direction.findings.map((finding, index) => (
                                <li
                                    key={index}
                                    data-a="ai:direct:finding"
                                    className="py-2"
                                >
                                    <p className="text-[11.5px] font-medium text-text-primary">
                                        {finding.title}
                                    </p>
                                    <p className="mt-0.5 text-[11px] leading-relaxed text-text-secondary">
                                        {finding.detail}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}

                    <p className="mt-2 text-[10.5px] leading-relaxed text-text-secondary">
                        Read from every chapter’s shape, not its prose. Free — judging the
                        writing itself is what the agents above are for.
                    </p>
                </div>
            )}

            <div className="mb-2.5 mt-4 flex items-baseline justify-between">
                <h3 className="text-[14px] font-semibold text-text-primary">
                    Editorial Overview
                </h3>
            </div>

            <div className="grid grid-cols-2 gap-2.5">
                {tiles.map((tile) => (
                    <div
                        key={tile.label}
                        className="flex h-[62px] items-center gap-2.5 rounded-tile border border-border-default px-3"
                    >
                        <span
                            className={`grid h-[25px] w-[25px] shrink-0 place-items-center rounded-full ${tile.tint}`}
                        >
                            <Icon name={tile.icon} size={14} />
                        </span>
                        <div className="min-w-0">
                            <p className="text-[17px] font-semibold leading-none text-text-primary">
                                {tile.value}
                            </p>
                            <p className="mt-1 truncate text-[11.5px] text-text-secondary">
                                {tile.label}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mb-1 mt-4 flex items-baseline justify-between">
                <h3 className="text-[14px] font-semibold text-text-primary">AI Agents</h3>
            </div>

            <ul>
                {agents.map((agent) => {
                    const style = AGENT_STYLE[agent.value] ?? {
                        icon: 'hex',
                        tile: 'bg-bg-subtle text-text-secondary',
                    };
                    const mine = open.filter((f) => f.agent === agent.value);
                    const busy = running === agent.value;

                    // Worst open severity drives the badge tone, so a single
                    // critical finding cannot hide behind four suggestions.
                    const tone = mine.some((f) => f.severity === 'critical')
                        ? 'critical'
                        : mine.some((f) => f.severity === 'warning')
                          ? 'warning'
                          : 'suggestion';

                    return (
                        <li key={agent.value}>
                            <div className="flex h-[48px] items-center gap-2.5 border-b border-border-divider">
                                <span
                                    className={`grid h-[31px] w-[31px] shrink-0 place-items-center rounded-tile ${style.tile}`}
                                >
                                    <Icon name={style.icon} size={17} />
                                </span>

                                <button
                                    data-a={`ai:agent:${agent.value}`}
                                    onClick={() => onOpenAgent(agent.value)}
                                    className="min-w-0 flex-1 text-left"
                                >
                                    <p className="truncate text-[12.5px] font-medium text-text-primary">
                                        {agent.label}
                                    </p>
                                    <p className="truncate text-[11.5px] text-text-secondary">
                                        {mine.length > 0
                                            ? `${mine.length} open finding${mine.length === 1 ? '' : 's'}.`
                                            : agent.description}
                                    </p>
                                </button>

                                <button
                                    data-a={`ai:run:${agent.value}`}
                                    onClick={() => onRunAgent(agent.value)}
                                    disabled={busy}
                                    title={`Run ${agent.label}`}
                                    className="shrink-0 rounded px-1.5 py-1 text-[11px] font-semibold text-brand-primary transition hover:bg-brand-primary-subtle disabled:opacity-50"
                                >
                                    {busy ? <Spinner dark /> : 'Run'}
                                </button>

                                {mine.length > 0 ? (
                                    <span
                                        className={`grid h-[17px] min-w-[17px] shrink-0 place-items-center rounded-badge px-1 text-[10.5px] font-semibold ${SEVERITY_BADGE[tone]}`}
                                    >
                                        {mine.length}
                                    </span>
                                ) : (
                                    <span className="shrink-0 text-status-success-check">
                                        <Icon name="check" size={16} strokeWidth={2.2} />
                                    </span>
                                )}

                                <span className="shrink-0 text-text-secondary">
                                    <Icon name="right" size={15} />
                                </span>
                            </div>
                        </li>
                    );
                })}
            </ul>

            <SampleNotice />
        </div>
    );
}

/**
 * Said whenever reviews here run on heuristics rather than a model: the
 * agents are named like editors, and without this an author would take
 * template findings for an AI's reading. Free, so the credits are named too.
 */
function SampleNotice() {
    const simulated = usePage().props.editorial?.simulated;

    if (!simulated) return null;

    return (
        <p
            data-a="ai:sample-notice"
            className="mt-3.5 rounded-tile border border-dashed border-border-default p-2.5 text-[10.5px] leading-relaxed text-text-secondary"
        >
            <b className="text-text-primary">Sample output.</b> Reviews here run
            automated checks, not an AI model: long sentences, passive voice,
            filler, unsourced figures, mixed spelling. They cannot assess an
            argument or verify a fact, and they use no credits.
        </p>
    );
}

function AgentFindings({ agent, findings, onBack, onResolve, onShowInText }) {
    const open = findings.filter((f) => f.status === 'open');
    const resolved = findings.length - open.length;

    return (
        <div className="p-3.5">
            <button
                data-a="ai:agent:back"
                onClick={onBack}
                className="mb-3 flex items-center gap-1 text-[11.5px] font-medium text-brand-primary hover:underline"
            >
                <Icon name="left" size={14} /> All agents
            </button>

            <h3 className="text-[14px] font-semibold text-text-primary">
                {agent?.label ?? 'Agent'}
            </h3>
            <p className="mt-0.5 text-[11.5px] text-text-secondary">
                {open.length} open · {resolved} resolved
            </p>

            <SampleNotice />

            {open.length === 0 ? (
                <p className="mt-4 rounded-tile bg-status-success-bg px-3 py-2.5 text-[11.5px] text-status-success">
                    Nothing open from this agent. Run it again after editing to
                    re-check.
                </p>
            ) : (
                <ul className="mt-3 space-y-2">
                    {open.map((finding) => (
                        <li
                            key={finding.id}
                            className="rounded-tile border border-border-default p-2.5"
                        >
                            <div className="flex items-start gap-2">
                                <span
                                    className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${
                                        finding.severity === 'critical'
                                            ? 'bg-status-danger'
                                            : finding.severity === 'warning'
                                              ? 'bg-status-warning'
                                              : 'bg-status-info'
                                    }`}
                                />
                                <p className="text-[12px] font-semibold leading-snug text-text-primary">
                                    {finding.title}
                                </p>
                                {finding.simulated && (
                                    <span
                                        title="From automated checks, not an AI model"
                                        className="ml-auto shrink-0 rounded-badge bg-bg-subtle px-1.5 py-0.5 text-[9.5px] font-semibold uppercase tracking-wide text-text-secondary"
                                    >
                                        Sample
                                    </span>
                                )}
                            </div>

                            {finding.detail && (
                                <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                                    {finding.detail}
                                </p>
                            )}

                            {finding.excerpt && (
                                <p className="mt-1.5 border-l-2 border-border-default pl-2 text-[11.5px] italic leading-snug text-text-secondary">
                                    {finding.excerpt}
                                </p>
                            )}

                            {finding.stale && (
                                <p className="mt-1.5 rounded bg-status-warning-bg px-1.5 py-1 text-[10.5px] font-semibold text-status-warning">
                                    The text this refers to has changed since the
                                    review ran.
                                </p>
                            )}

                            <div className="mt-2 flex items-center gap-3">
                                {finding.excerpt && (
                                    <button
                                        data-a={`ai:show:${finding.id}`}
                                        onClick={() => onShowInText?.(finding)}
                                        className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                                    >
                                        Show in text
                                    </button>
                                )}
                                {finding.suggestion && (
                                    <button
                                        data-a={`ai:accept:${finding.id}`}
                                        onClick={() => onResolve(finding, 'accepted')}
                                        className="text-[11.5px] font-semibold text-status-success hover:underline"
                                    >
                                        Apply
                                    </button>
                                )}
                                <button
                                    data-a={`ai:dismiss:${finding.id}`}
                                    onClick={() => onResolve(finding, 'dismissed')}
                                    className="text-[11.5px] font-medium text-text-secondary hover:text-text-primary"
                                >
                                    Resolve
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Preflight list plus version history — both computed, nothing invented. */
function ChecksTab({ findings, versions, onRestore }) {
    const checks = [
        {
            label: 'AI findings resolved',
            ok: findings.length === 0,
            detail: `${findings.length} open`,
        },
        {
            label: 'Critical issues clear',
            ok: !findings.some((f) => f.severity === 'critical'),
            detail: `${findings.filter((f) => f.severity === 'critical').length} critical`,
        },
        {
            label: 'Version history',
            ok: versions.length > 0,
            detail: `${versions.length} saved`,
        },
    ];

    return (
        <div className="p-3.5">
            <ul className="space-y-1.5">
                {checks.map((check) => (
                    <li
                        key={check.label}
                        className="flex items-center gap-2.5 rounded-tile border border-border-default px-3 py-2.5"
                    >
                        <span
                            className={
                                check.ok ? 'text-status-success-check' : 'text-status-warning'
                            }
                        >
                            <Icon name={check.ok ? 'check' : 'info'} size={16} strokeWidth={2} />
                        </span>
                        <span className="flex-1 text-[12.5px] text-text-body">
                            {check.label}
                        </span>
                        <span className="text-[11px] text-text-secondary">
                            {check.detail}
                        </span>
                    </li>
                ))}
            </ul>

            <h4 className="mb-1.5 mt-4 text-[11px] font-semibold uppercase tracking-wide text-text-secondary">
                Version history
            </h4>

            {versions.length === 0 ? (
                <p className="text-[11.5px] leading-relaxed text-text-secondary">
                    No versions yet. One is saved as you write, and whenever you
                    press Ctrl/Cmd+S.
                </p>
            ) : (
                <ul className="space-y-1.5">
                    {versions.map((version) => (
                        <li
                            key={version.id}
                            className="rounded-tile border border-border-default p-2.5"
                        >
                            <div className="flex items-baseline justify-between">
                                <span className="text-[12px] font-semibold text-text-primary">
                                    v{version.version_number}
                                </span>
                                <span className="text-[10px] uppercase tracking-wide text-text-secondary">
                                    {version.reason}
                                </span>
                            </div>
                            <p className="mt-0.5 text-[11px] text-text-secondary">
                                {version.created_human} ·{' '}
                                {version.word_count.toLocaleString()} words
                            </p>
                            <button
                                data-a={`checks:restore:${version.id}`}
                                onClick={() => onRestore(version.id)}
                                className="mt-1 text-[11.5px] font-semibold text-brand-primary hover:underline"
                            >
                                Restore
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function EmptyTab({ title, what, milestone }) {
    return (
        <div className="grid h-full place-items-center p-8 text-center">
            <div className="max-w-[280px]">
                <p className="text-[13px] font-semibold text-text-primary">{title}</p>
                <p className="mt-1.5 text-[11.5px] leading-relaxed text-text-secondary">
                    {what}
                </p>
                <p className="mt-3 inline-block rounded-badge bg-bg-subtle px-2.5 py-1 text-[11px] font-semibold text-text-secondary">
                    Arrives in {milestone}
                </p>
            </div>
        </div>
    );
}

function Spinner({ dark = false }) {
    return (
        <span
            className={`inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-t-transparent ${
                dark ? 'text-brand-primary' : 'text-white'
            }`}
            aria-hidden="true"
        />
    );
}
