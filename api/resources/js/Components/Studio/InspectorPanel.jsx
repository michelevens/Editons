import { useState } from 'react';
import CitationsTab from './CitationsTab';
import { NotBuilt } from './Shell';

/**
 * The right rail: AI Editor, Comments, Research, Checks, History.
 *
 * AI Editor and History are backed by real engines. Comments and Research are
 * not, and say so rather than listing invented PDFs and conversations.
 */

const SEVERITY = {
    critical: { dot: 'bg-rose-500', chip: 'bg-rose-50 text-rose-700', label: 'Critical' },
    warning: { dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-700', label: 'Warning' },
    suggestion: { dot: 'bg-sky-500', chip: 'bg-sky-50 text-sky-700', label: 'Suggestion' },
};

export default function InspectorPanel({
    agents,
    findings,
    versions,
    running,
    sectionId,
    bookId,
    onRunAgent,
    onResolve,
    onRestore,
    onOpenLibrary,
}) {
    const [tab, setTab] = useState('ai');

    const tabs = [
        { key: 'ai', label: 'AI Editor', ready: true },
        { key: 'comments', label: 'Comments', ready: false },
        { key: 'research', label: 'Research', ready: true },
        { key: 'history', label: 'History', ready: true },
    ];

    return (
        <aside className="flex w-80 shrink-0 flex-col overflow-hidden border-l border-slate-200 bg-slate-50">
            <div className="flex border-b border-slate-200 bg-white">
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        onClick={() => setTab(t.key)}
                        className={`flex-1 border-b-2 px-2 py-2.5 text-xs font-semibold transition ${
                            tab === t.key
                                ? 'border-indigo-600 text-indigo-700'
                                : t.ready
                                  ? 'border-transparent text-slate-500 hover:text-slate-800'
                                  : 'border-transparent text-slate-400'
                        }`}
                    >
                        {t.label}
                        {!t.ready && <span className="ml-0.5 align-super text-[8px]">•</span>}
                    </button>
                ))}
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {tab === 'ai' && (
                    <AiEditor
                        agents={agents}
                        findings={findings}
                        running={running}
                        onRunAgent={onRunAgent}
                        onResolve={onResolve}
                    />
                )}
                {tab === 'history' && (
                    <History versions={versions} onRestore={onRestore} />
                )}
                {tab === 'comments' && (
                    <NotBuilt
                        title="Comments"
                        milestone="Milestone 4"
                        what="Threaded comments and suggestions on a shared manuscript, with roles and a review workflow."
                    />
                )}
                {tab === 'research' && (
                    <CitationsTab
                        sectionId={sectionId}
                        bookId={bookId}
                        onManageLibrary={onOpenLibrary}
                    />
                )}
            </div>
        </aside>
    );
}

function AiEditor({ agents, findings, running, onRunAgent, onResolve }) {
    const open = findings.filter((f) => f.status === 'open');
    const counts = {
        critical: open.filter((f) => f.severity === 'critical').length,
        warning: open.filter((f) => f.severity === 'warning').length,
        suggestion: open.filter((f) => f.severity === 'suggestion').length,
    };

    return (
        <div className="p-3">
            {/* Chapter health — counts computed from real findings. */}
            <div className="mb-3 grid grid-cols-3 gap-2">
                {Object.entries(counts).map(([sev, n]) => (
                    <div
                        key={sev}
                        className={`rounded-lg px-2 py-2 text-center ${SEVERITY[sev].chip}`}
                    >
                        <p className="text-lg font-bold leading-none">{n}</p>
                        <p className="mt-1 text-[10px] font-semibold uppercase tracking-wide">
                            {SEVERITY[sev].label}
                        </p>
                    </div>
                ))}
            </div>

            <p className="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                Editorial agents
            </p>

            <ul className="mb-4 space-y-1.5">
                {agents.map((a) => {
                    const mine = open.filter((f) => f.agent === a.value).length;
                    const busy = running === a.value;

                    return (
                        <li
                            key={a.value}
                            className="rounded-lg border border-slate-200 bg-white p-2.5"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-[13px] font-semibold text-slate-800">
                                    {a.label}
                                </span>
                                <button
                                    onClick={() => onRunAgent(a.value)}
                                    disabled={busy}
                                    className="shrink-0 rounded bg-indigo-600 px-2 py-1 text-[11px] font-bold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {busy ? 'Running…' : 'Run'}
                                </button>
                            </div>
                            <p className="mt-1 text-[11px] leading-snug text-slate-500">
                                {a.description}
                            </p>
                            {mine > 0 && (
                                <p className="mt-1 text-[11px] font-semibold text-indigo-600">
                                    {mine} open finding{mine === 1 ? '' : 's'}
                                </p>
                            )}
                        </li>
                    );
                })}
            </ul>

            <p className="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                Findings
            </p>

            {open.length === 0 ? (
                <p className="px-1 text-[11px] leading-relaxed text-slate-500">
                    No open findings. Run an agent above to review this chapter.
                </p>
            ) : (
                <ul className="space-y-2">
                    {open.map((f) => (
                        <li
                            key={f.id}
                            className="rounded-lg border border-slate-200 bg-white p-2.5"
                        >
                            <div className="flex items-start gap-2">
                                <span
                                    className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${SEVERITY[f.severity].dot}`}
                                />
                                <div className="min-w-0">
                                    <p className="text-[12px] font-bold leading-snug text-slate-800">
                                        {f.title}
                                    </p>
                                    <p className="text-[10px] uppercase tracking-wide text-slate-400">
                                        {f.agent_label}
                                    </p>
                                </div>
                            </div>

                            {f.detail && (
                                <p className="mt-1.5 text-[11px] leading-relaxed text-slate-600">
                                    {f.detail}
                                </p>
                            )}

                            {f.excerpt && (
                                <p className="mt-1.5 border-l-2 border-slate-200 pl-2 text-[11px] italic leading-snug text-slate-500">
                                    {f.excerpt}
                                </p>
                            )}

                            {/* A finding whose text has since been edited is
                                shown as stale rather than silently pointing at
                                a sentence that no longer exists. */}
                            {f.stale && (
                                <p className="mt-1.5 rounded bg-amber-50 px-1.5 py-1 text-[10px] font-semibold text-amber-700">
                                    The text this refers to has changed since
                                    the review ran.
                                </p>
                            )}

                            <div className="mt-2 flex gap-3">
                                {f.suggestion && (
                                    <button
                                        onClick={() => onResolve(f, 'accepted')}
                                        className="text-[11px] font-bold text-emerald-700 hover:text-emerald-900"
                                    >
                                        Accept fix
                                    </button>
                                )}
                                <button
                                    onClick={() => onResolve(f, 'dismissed')}
                                    className="text-[11px] font-semibold text-slate-500 hover:text-slate-800"
                                >
                                    Dismiss
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {/* The driver's limits, stated where the findings are, not buried
                in documentation. */}
            <p className="mt-4 rounded-lg border border-dashed border-slate-300 p-2.5 text-[10px] leading-relaxed text-slate-500">
                Reviews currently run local heuristics — long sentences,
                passive voice, filler, unsourced figures, mixed spelling. They
                cannot assess an argument or verify a fact. Connecting a
                language model is one configuration change; nothing above the
                driver needs to move.
            </p>
        </div>
    );
}

function History({ versions, onRestore }) {
    return (
        <div className="p-3">
            {versions.length === 0 ? (
                <p className="px-1 text-[11px] leading-relaxed text-slate-500">
                    No versions yet. One is saved as you write, and whenever
                    you press Ctrl/Cmd+S.
                </p>
            ) : (
                <ul className="space-y-1.5">
                    {versions.map((v) => (
                        <li
                            key={v.id}
                            className="rounded-lg border border-slate-200 bg-white p-2.5"
                        >
                            <div className="flex items-baseline justify-between">
                                <span className="text-xs font-bold text-slate-800">
                                    v{v.version_number}
                                </span>
                                <span className="text-[10px] uppercase tracking-wide text-slate-400">
                                    {v.reason}
                                </span>
                            </div>
                            <p className="mt-0.5 text-[11px] text-slate-500">
                                {v.created_human} · {v.word_count.toLocaleString()} words
                            </p>
                            {v.label && (
                                <p className="mt-0.5 text-[11px] italic text-slate-500">
                                    {v.label}
                                </p>
                            )}
                            <button
                                onClick={() => onRestore(v.id)}
                                className="mt-1.5 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800"
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
