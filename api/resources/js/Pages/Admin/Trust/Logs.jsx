import AdminLayout, { Panel, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Empty } from '@/Components/Admin/Kit';
import { bytes } from '@/Components/Admin/TrustKit';

/**
 * The application log, owner-only. Secrets, tokens, email addresses and
 * card-like numbers are removed on the server before a line is sent here.
 */
const LEVEL_TONE = { emergency: 'danger', alert: 'danger', critical: 'danger', error: 'danger', warning: 'warning', notice: 'info', info: 'info', debug: 'neutral' };

export default function Logs({ log, file, filters, levels, entries, elsewhere }) {
    const [q, setQ] = useState(filters.q);
    const go = (next) =>
        router.get(route('admin.logs.index'), clean({ file, level: filters.level, q: filters.q, lines: filters.lines, ...next }), { preserveState: true });

    return (
        <AdminLayout title="Logs" active="logs">
            <p className="mb-3 text-[12.5px] text-text-secondary" data-a="admin:logs:channel">
                Channel <code>{log.channel}</code> writes to {log.drivers.join(', ') || 'nothing configured'}.
            </p>

            {log.stream_only ? (
                <Panel title="The log is a stream here" data-a="admin:logs:stream-only">
                    <p className="text-[13px] text-text-body">
                        This environment logs to the container&rsquo;s output ({log.drivers.join(', ')}), not to files, so there is nothing on disk to read back. Railway
                        keeps that stream in its own log viewer for the service.
                    </p>
                    <p className="mt-2 text-[13px] text-text-body">What the console keeps itself:</p>
                    <ul className="mt-1 list-disc pl-5 text-[13px]">
                        <li>
                            <Link href={route('admin.errors.index')} className="text-brand-primary hover:underline">
                                Errors
                            </Link>{' '}
                            — every exception Laravel would log, grouped ({elsewhere.open_errors} open).
                        </li>
                        <li>
                            <Link href={route('admin.mail.index')} className="text-brand-primary hover:underline">
                                Email log
                            </Link>{' '}
                            — every message sent or failed ({elsewhere.mail_failed_24h} failed in 24 hours).
                        </li>
                    </ul>
                </Panel>
            ) : (
                <>
                    <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                        <label className="flex items-center gap-1.5 text-[12px] text-text-secondary">
                            File
                            <select data-a="admin:logs:file" value={file ?? ''} onChange={(e) => go({ file: e.target.value })} className={`${inputClass} w-auto py-1.5`}>
                                {log.files.map((f) => (
                                    <option key={f.name} value={f.name}>
                                        {f.name} ({bytes(f.bytes)})
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex items-center gap-1.5 text-[12px] text-text-secondary">
                            Level
                            <select data-a="admin:logs:level" value={filters.level} onChange={(e) => go({ level: e.target.value })} className={`${inputClass} w-auto py-1.5`}>
                                <option value="">All</option>
                                {levels.map((l) => (
                                    <option key={l} value={l}>
                                        {l} and worse
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex items-center gap-1.5 text-[12px] text-text-secondary">
                            Last
                            <select data-a="admin:logs:lines" value={filters.lines} onChange={(e) => go({ lines: e.target.value })} className={`${inputClass} w-auto py-1.5`}>
                                {[50, 200, 500, 1000].map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <form
                            role="search"
                            className="flex min-w-0 flex-1 gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                go({ q });
                            }}
                        >
                            <label htmlFor="logs-q" className="sr-only">
                                Search the log
                            </label>
                            <input id="logs-q" data-a="admin:logs:search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search (after redaction)" className={`${inputClass} min-w-0`} />
                            <button type="submit" className="tap rounded-control bg-bg-inverse px-3 text-[12.5px] font-semibold text-text-inverse">
                                Search
                            </button>
                        </form>
                    </div>

                    <ul className="mt-4 space-y-1.5" data-a="admin:logs:entries">
                        {entries.length === 0 && (
                            <li>
                                <Empty>No entries match.</Empty>
                            </li>
                        )}
                        {entries.map((e, i) => (
                            <li key={i} className="rounded-tile border border-border-default bg-bg-surface px-3 py-2 text-[12px]">
                                <div className="flex flex-wrap items-baseline gap-2">
                                    <Badge tone={LEVEL_TONE[e.level] ?? 'neutral'}>{e.level}</Badge>
                                    <span className="text-text-muted">{e.at ? formatDate(e.at.replace(' ', 'T')) : ''}</span>
                                    {e.env && <span className="text-text-muted">{e.env}</span>}
                                </div>
                                <pre className="mt-1 max-h-[220px] overflow-auto whitespace-pre-wrap break-words font-mono text-[11.5px] text-text-body" tabIndex={0}>
                                    {e.message}
                                </pre>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </AdminLayout>
    );
}

function clean(params) {
    return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}
