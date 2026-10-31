import Dialog from '@/Components/Dialog';
import { Panel, buttonClass, formatDate, inputClass } from '@/Layouts/AdminLayout';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge, Empty, Errors, Table } from '@/Components/Admin/Kit';

/**
 * An account's books, and moving chosen ones to another account (ADR —
 * moving books between any accounts).
 *
 * Counts, never text. The owner ticks books and opens "Move to another
 * account…": find the target by email or name, preview — the same plan as
 * `books:transfer`, nothing written — then give a reason and confirm. All
 * or nothing, audited per book on the server. Keeping the previous owner as
 * an editor is on by default for a person's account and does not apply to a
 * house account, which is not a person.
 */
export default function AccountBooks({ account, books, owner, keepPreviousDefault }) {
    const [chosen, setChosen] = useState([]);
    const [moving, setMoving] = useState(false);

    const toggle = (id) => setChosen((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
    const all = books.length > 0 && chosen.length === books.length;

    const head = [
        ...(owner ? [
            <input
                key="all"
                type="checkbox"
                aria-label="Choose every book"
                data-a="admin:account:books:all"
                checked={all}
                onChange={() => setChosen(all ? [] : books.map((b) => b.id))}
            />,
        ] : []),
        'Book', 'Words', 'Status', 'Members', 'Product', 'ISBNs', 'Versions', 'Updated',
    ];

    return (
        <Panel
            title={`Books (${books.length})`}
            detail="Counts only: open a book with View as."
            data-a="admin:account:books"
            actions={owner && books.length > 0 ? (
                <button
                    type="button"
                    data-a="admin:account:books:move"
                    disabled={chosen.length === 0}
                    onClick={() => setMoving(true)}
                    className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle disabled:opacity-50`}
                >
                    Move to another account…{chosen.length > 0 ? ` (${chosen.length})` : ''}
                </button>
            ) : null}
        >
            {books.length === 0 ? (
                <Empty>No books.</Empty>
            ) : (
                <BooksTable head={head} books={books} owner={owner} chosen={chosen} toggle={toggle} />
            )}
            {moving && (
                <MoveDialog
                    account={account}
                    books={books.filter((b) => chosen.includes(b.id))}
                    keepPreviousDefault={keepPreviousDefault}
                    onClose={() => setMoving(false)}
                    onMoved={() => { setMoving(false); setChosen([]); }}
                />
            )}
        </Panel>
    );
}

function BooksTable({ head, books, owner, chosen, toggle }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[360px] text-left text-[12.5px]">
                <thead className="text-text-secondary">
                    <tr>
                        {head.map((cell, i) => (
                            <th key={i} className="py-1.5 pr-3 font-medium">{cell}</th>
                        ))}
                    </tr>
                </thead>
                <tbody className="tabular-nums">
                    {books.map((book) => (
                        <tr key={book.id} className="border-t border-border-divider" data-a={`admin:account:book:${book.id}`}>
                            {owner && (
                                <td className="py-1.5 pr-3">
                                    <input
                                        type="checkbox"
                                        aria-label={`Choose “${book.title}”`}
                                        data-a="admin:account:book:choose"
                                        checked={chosen.includes(book.id)}
                                        onChange={() => toggle(book.id)}
                                    />
                                </td>
                            )}
                            <td className="py-1.5 pr-3">
                                <span className="text-text-muted">#{book.id}</span>{' '}
                                <span className="font-medium text-text-primary">{book.title}</span>
                            </td>
                            <td className="py-1.5 pr-3">{book.words.toLocaleString()}</td>
                            <td className="py-1.5 pr-3">{book.status}</td>
                            <td className="py-1.5 pr-3">{book.members ?? 0}</td>
                            <td className="py-1.5 pr-3">{book.product === 'on_sale' ? <Badge tone="success">on sale</Badge> : book.product === 'draft' ? 'draft' : '—'}</td>
                            <td className="py-1.5 pr-3">{book.isbns?.length ? book.isbns.join(', ') : '—'}</td>
                            <td className="py-1.5 pr-3">{book.versions}</td>
                            <td className="py-1.5 pr-3">{formatDate(book.updated_at, false)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function MoveDialog({ account, books, keepPreviousDefault, onClose, onMoved }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState([]);
    const [target, setTarget] = useState(null);
    const [plan, setPlan] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const form = useForm({ keep_previous: keepPreviousDefault, reason: '' });
    // A house account is not a person: nobody to keep, so no choice to show.
    const fromHouse = account.house;

    useEffect(() => {
        if (target || q.trim().length < 2) {
            setFound([]);
            return undefined;
        }
        const timer = setTimeout(async () => {
            try {
                const { data } = await window.axios.get(route('admin.accounts.transfer-targets', account.id), { params: { q } });
                setFound(data.accounts);
            } catch {
                setFound([]);
            }
        }, 250);
        return () => clearTimeout(timer);
    }, [q, target, account.id]);

    const payload = (extra = {}) => ({
        books: books.map((b) => b.id),
        to: target?.id,
        ...(fromHouse ? {} : { keep_previous: form.data.keep_previous }),
        ...extra,
    });

    const preview = async () => {
        setError(null);
        setPlan(null);
        setBusy(true);
        try {
            const { data } = await window.axios.post(route('admin.accounts.books.transfer.preview', account.id), payload());
            setPlan(data);
        } catch (e) {
            setError(e.response?.data?.message ?? 'The preview failed.');
        } finally {
            setBusy(false);
        }
    };

    const apply = (event) => {
        event.preventDefault();
        form.transform((data) => payload({ reason: data.reason }));
        form.post(route('admin.accounts.books.transfer', account.id), { preserveScroll: true, onSuccess: onMoved });
    };

    const label = (a) => (a.house ? `${a.name} (house)` : `${a.name} <${a.email}>`);

    return (
        <Dialog label="Move books to another account" onClose={onClose} className="max-h-[90vh] w-[560px] max-w-full overflow-y-auto rounded-panel bg-bg-surface p-5">
            <form onSubmit={apply} className="text-left" data-a="admin:account:move-form">
                <h2 className="text-[16px] font-bold text-text-primary">Move {books.length} book{books.length === 1 ? '' : 's'} to another account</h2>
                <p className="mt-1 text-[12.5px] text-text-secondary">
                    Preview first: nothing moves until you confirm. The product and ISBNs go with each book; sales history stays with whoever owned it when it sold.
                </p>
                <ul className="mt-2 max-h-[96px] overflow-y-auto text-[12.5px] text-text-body">
                    {books.map((b) => <li key={b.id}>#{b.id} {b.title}</li>)}
                </ul>

                <div className="mt-3">
                    <label htmlFor="move-target" className="text-[12.5px] font-medium text-text-primary">To (search by email or name)</label>
                    {target ? (
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-[13px]" data-a="admin:account:move:target">
                            <span className="font-medium text-text-primary">{label(target)}</span>
                            <button type="button" onClick={() => { setTarget(null); setPlan(null); }} className="text-[12.5px] text-brand-primary hover:underline">Change</button>
                        </div>
                    ) : (
                        <>
                            <input id="move-target" data-a="admin:account:move:search" data-autofocus value={q} onChange={(e) => setQ(e.target.value)} autoComplete="off" className={`${inputClass} mt-1`} />
                            {found.length > 0 && (
                                <ul className="mt-1 rounded-control border border-border-default text-[12.5px]" role="listbox" aria-label="Accounts">
                                    {found.map((a) => (
                                        <li key={a.id}>
                                            <button type="button" role="option" aria-selected="false" data-a="admin:account:move:pick" onClick={() => { setTarget(a); setPlan(null); }} className="block w-full truncate px-3 py-1.5 text-left hover:bg-bg-subtle">
                                                #{a.id} {label(a)}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                </div>

                {!fromHouse && (
                    <label className="mt-3 flex items-start gap-2 text-[12.5px] text-text-primary">
                        <input type="checkbox" data-a="admin:account:move:keep" className="mt-0.5" checked={form.data.keep_previous} onChange={(e) => { form.setData('keep_previous', e.target.checked); setPlan(null); }} />
                        <span>Keep {account.name} as an editor of each book</span>
                    </label>
                )}

                <div className="mt-3 flex flex-wrap gap-2">
                    <button type="button" data-a="admin:account:move:preview" disabled={!target || busy} onClick={preview} className={`${buttonClass} border border-border-default text-text-primary hover:bg-bg-subtle disabled:opacity-50`}>
                        {busy ? 'Checking…' : 'Preview'}
                    </button>
                    <button type="button" onClick={onClose} className={`${buttonClass} text-text-secondary hover:bg-bg-subtle`}>Cancel</button>
                </div>
                {error && <p role="alert" className="mt-2 text-[12.5px] text-status-danger">{error}</p>}

                {plan && (
                    <div className="mt-3" data-a="admin:account:move:plan">
                        {plan.problems.map((p) => <p key={p} className="text-[12.5px] text-status-danger">{p}</p>)}
                        {plan.warnings.map((w) => <p key={w} className="text-[12.5px] text-status-warning">{w}</p>)}
                        <Table
                            head={['Book', 'Sections', 'Exports', 'Product', 'ISBNs', 'Previous owner', 'Result']}
                            rows={plan.books.map((row) => [
                                <span key="t" className="font-medium text-text-primary">#{row.id} {row.title ?? ''}</span>,
                                row.moves?.sections ?? '—',
                                row.moves?.exports ?? '—',
                                row.moves?.product ? (row.moves.product.on_sale ? 'on sale' : 'draft') : '—',
                                row.moves?.isbns ?? '—',
                                row.previous_owner ?? '—',
                                row.ok ? <Badge key="r" tone="success">ready</Badge> : <span key="r" className="text-status-danger">{row.problems.join(' ') || 'blocked'}</span>,
                            ])}
                        />
                        {plan.books.flatMap((row) => (row.warnings ?? []).map((w) => (
                            <p key={`${row.id}${w}`} className="mt-1 text-[12px] text-status-warning">#{row.id}: {w}</p>
                        )))}
                        {plan.ok && (
                            <div className="mt-3">
                                <label htmlFor="move-reason" className="text-[12.5px] font-medium text-text-primary">Reason (kept in the audit log, for every book)</label>
                                <textarea id="move-reason" data-a="admin:account:move:reason" required minLength={4} rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className={`${inputClass} mt-1`} />
                                <Errors errors={form.errors} />
                                <button type="submit" data-a="admin:account:move:confirm" disabled={form.processing} className={`${buttonClass} mt-3 bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                                    Move {plan.books.length} book{plan.books.length === 1 ? '' : 's'} to {plan.to.name}
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </form>
        </Dialog>
    );
}
