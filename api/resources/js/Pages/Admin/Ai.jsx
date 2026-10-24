import AdminLayout, { Panel, formatDate, usd } from '@/Layouts/AdminLayout';
import { Link } from '@inertiajs/react';
import { Badge, Empty, ReasonAction, Table } from '@/Components/Admin/Kit';

/**
 * AI usage, cost and the kill switches.
 *
 * Totals are of known costs; calls whose model has no price are counted
 * beside them as "unknown", never added as zero. Sample output from the
 * fake drivers is counted apart: it is not AI spend.
 */
export default function Ai({ windows, by_feature: byFeature, by_model: byModel, top_accounts: top, failures, switches, default_cap: defaultCap, priced_models: priced, can_edit: canEdit }) {
    const all = switches.find((s) => s.key === 'all');

    return (
        <AdminLayout title="AI usage" active="ai">
            {all?.paused && (
                <p role="status" className="mb-4 rounded-tile bg-status-danger-bg px-3 py-2 text-[13px] font-semibold text-status-danger" data-a="admin:ai:all-paused">
                    All AI is paused: {all.reason}
                </p>
            )}

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3" data-a="admin:ai:windows">
                <Window label="Today" w={windows.today} />
                <Window label="Last 7 days" w={windows.d7} />
                <Window label="Last 30 days" w={windows.d30} />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Panel title="Kill switches" detail={canEdit ? 'A paused feature refuses before anything is charged, with a sentence for the author.' : 'Only the platform owner can pause or resume AI.'}>
                    <ul className="divide-y divide-border-divider text-[13px]" data-a="admin:ai:switches">
                        {switches.map((s) => (
                            <li key={s.key} className="flex flex-wrap items-center gap-2 py-2">
                                <span className="min-w-0 flex-1">
                                    <span className="font-medium text-text-primary">{s.label}</span>
                                    {s.paused && s.reason && <span className="block text-[12px] text-text-secondary">“{s.reason}”{s.updated_by_name && ` — ${s.updated_by_name}, ${formatDate(s.updated_at)}`}</span>}
                                </span>
                                <Badge tone={s.paused ? 'danger' : 'success'}>{s.paused ? 'Paused' : 'On'}</Badge>
                                {canEdit && (
                                    <ReasonAction
                                        label={s.paused ? 'Resume' : 'Pause'}
                                        route={route('admin.ai.switches')}
                                        dataA={`admin:ai:switch:${s.key}`}
                                        danger={!s.paused}
                                        extra={{ key: s.key, paused: !s.paused }}
                                        detail={s.paused ? `${s.label} will be available again.` : `${s.label} will refuse with “paused by Ecrivain — try again later”. Nothing is charged while paused.`}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-3 text-[12px] text-text-secondary">
                        Monthly cap for accounts without their own: {defaultCap === null ? 'none' : usd(defaultCap)} (PLATFORM_AI_DEFAULT_MONTHLY_CAP_USD). Per-account caps are set on the account page.
                    </p>
                </Panel>

                <Panel title="Top accounts" detail="Last 30 days, by known cost.">
                    {top.length === 0 ? (
                        <Empty>No AI calls yet.</Empty>
                    ) : (
                        <Table
                            head={['Account', 'Calls', 'Cost']}
                            rows={top.map((row) => [
                                <Link key="a" href={route('admin.accounts.show', row.id)} className="text-brand-primary hover:underline">
                                    {row.email ?? row.name}
                                </Link>,
                                row.calls,
                                <CostCell key="c" cost={row.cost_usd} unknown={row.unknown_cost} />,
                            ])}
                        />
                    )}
                </Panel>

                <Panel title="By feature" detail="Last 30 days.">
                    <Breakdown rows={byFeature} />
                </Panel>

                <Panel title="By model" detail={`Priced: ${Object.entries(priced).map(([provider, models]) => `${provider} (${models.length})`).join(', ')}. Prices and their source are in config/platform.php.`}>
                    <Breakdown rows={byModel} />
                </Panel>

                <Panel title="Recent failures" className="xl:col-span-2">
                    {failures.length === 0 ? (
                        <Empty>No failed AI calls.</Empty>
                    ) : (
                        <ul className="divide-y divide-border-divider text-[12.5px]" data-a="admin:ai:failures">
                            {failures.map((f) => (
                                <li key={f.id} className="py-1.5">
                                    <span className="font-medium text-text-primary">{f.feature}</span>
                                    <span className="text-text-secondary">
                                        {' '}· {f.provider}
                                        {f.model && ` ${f.model}`} · {formatDate(f.at)}
                                        {f.user && (
                                            <>
                                                {' '}·{' '}
                                                <Link href={route('admin.accounts.show', f.user.id)} className="text-brand-primary hover:underline">
                                                    {f.user.email}
                                                </Link>
                                            </>
                                        )}
                                        {f.simulated && ' · sample output'}
                                    </span>
                                    {f.error && <p className="break-words text-text-muted">{f.error}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AdminLayout>
    );
}

function Window({ label, w }) {
    return (
        <section className="rounded-panel border border-border-default bg-bg-surface p-4">
            <h2 className="text-[12px] font-medium text-text-secondary">{label}</h2>
            <p className="mt-1 font-display text-[24px] font-extrabold tabular-nums text-text-primary">{usd(w.cost_usd)}</p>
            <p className="mt-1 text-[12px] text-text-secondary">
                {w.real} real call{w.real === 1 ? '' : 's'} · {w.simulated} sample · {w.failed} failed
            </p>
            {w.unknown_cost > 0 && <p className="text-[12px] font-medium text-status-warning">{w.unknown_cost} with unknown cost (unpriced model)</p>}
        </section>
    );
}

function CostCell({ cost, unknown }) {
    return (
        <span>
            {usd(cost)}
            {unknown > 0 && <span className="text-status-warning"> + {unknown} unknown</span>}
        </span>
    );
}

function Breakdown({ rows }) {
    if (rows.length === 0) return <Empty>No AI calls in the last 30 days.</Empty>;

    return (
        <Table
            head={['', 'Calls', 'Failed', 'Tokens in / out', 'Avg ms', 'Cost']}
            rows={rows.map((row) => [
                <span key="n" className="font-medium text-text-primary">
                    {row.label}
                    {row.simulated === row.calls && <span className="ml-1 text-text-muted">(sample)</span>}
                </span>,
                row.calls,
                row.failed,
                `${row.input_tokens.toLocaleString()} / ${row.output_tokens.toLocaleString()}`,
                row.latency_ms ?? '—',
                <CostCell key="c" cost={row.cost_usd} unknown={row.unknown_cost} />,
            ])}
        />
    );
}
