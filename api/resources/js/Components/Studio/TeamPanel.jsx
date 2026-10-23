import { useCallback, useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * Who else is on this book.
 *
 * Invitations are emailed when the server has a mail transport, and the
 * link is always shown for copying. Where mail goes nowhere (the "log"
 * mailer), the server says delivery is manual and the screen says so rather
 * than imply an email went out.
 */
export default function TeamPanel({ bookId }) {
    const [data, setData] = useState(null);
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('reviewer');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(null);
    const [removing, setRemoving] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/members`);
        setData(response.data);
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const invite = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            const response = await window.axios.post(`/books/${bookId}/members`, {
                email,
                role,
            });

            setData(response.data);
            setEmail('');
        } catch (failure) {
            setError(
                failure.response?.data?.errors?.email?.[0] ??
                    failure.response?.data?.message ??
                    'That invitation could not be sent.',
            );
        } finally {
            setBusy(false);
        }
    };

    const changeRole = async (member, next) => {
        const response = await window.axios.patch(`/members/${member.id}`, { role: next });
        setData(response.data);
    };

    const resend = async (member) => {
        const response = await window.axios.post(`/members/${member.id}/resend`);
        setData(response.data);
    };

    const remove = async (member) => {
        const response = await window.axios.delete(`/members/${member.id}`);
        setData(response.data);
        setRemoving(null);
    };

    const copyLink = async (member) => {
        try {
            await navigator.clipboard.writeText(member.invite_url);
            setCopied(member.id);
            window.setTimeout(() => setCopied(null), 2200);
        } catch {
            // Clipboard access is refused in plenty of ordinary situations.
            // Prompt is ugly but it always works, and the link is the whole
            // point of this screen.
            window.prompt('Copy this invitation link:', member.invite_url);
        }
    };

    if (!data) {
        return <p className="p-8 text-center text-[13px] text-text-secondary">Loading…</p>;
    }

    const full =
        data.seats.limit !== null && data.seats.used >= data.seats.limit;

    return (
        <div className="mx-auto max-w-[840px] p-8">
            <header className="mb-6">
                <h2 className="font-display text-[24px] font-bold tracking-[-0.03em] text-text-primary">
                    Team Members
                </h2>
                <p className="mt-1.5 text-[14px] text-text-secondary">
                    {data.seats.limit === null
                        ? `${data.seats.used} on this book.`
                        : `${data.seats.used} of ${data.seats.limit} seats used.`}
                </p>
            </header>

            <form
                onSubmit={invite}
                className="rounded-panel border border-border-default bg-bg-surface p-5"
            >
                <h3 className="text-[15px] font-semibold text-text-primary">
                    Invite someone
                </h3>

                <div className="mt-3.5 flex flex-wrap items-end gap-3">
                    <label className="min-w-[220px] flex-1">
                        <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                            Email
                        </span>
                        <input
                            type="email"
                            required
                            value={email}
                            data-a="team:email"
                            placeholder="name@example.com"
                            onChange={(event) => setEmail(event.target.value)}
                            className="h-[38px] w-full rounded-control border border-border-input px-3 text-[13.5px] focus:border-brand-primary focus:ring-0"
                        />
                    </label>

                    <label>
                        <span className="mb-1.5 block text-[12.5px] font-semibold text-text-primary">
                            Role
                        </span>
                        <select
                            value={role}
                            data-a="team:role"
                            onChange={(event) => setRole(event.target.value)}
                            className="h-[38px] rounded-control border border-border-input px-3 text-[13.5px] capitalize focus:border-brand-primary focus:ring-0"
                        >
                            {data.roles.map((option) => (
                                <option key={option} value={option} className="capitalize">
                                    {option}
                                </option>
                            ))}
                        </select>
                    </label>

                    <button
                        disabled={busy || full}
                        data-a="team:invite"
                        title={full ? 'Every seat on this plan is taken.' : undefined}
                        className="h-[38px] rounded-control bg-brand-fill px-4 text-[13.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {busy ? 'Inviting…' : 'Send invite'}
                    </button>
                </div>

                {error && (
                    <p role="alert" className="mt-2.5 text-[12.5px] font-medium text-status-danger">
                        {error}
                    </p>
                )}

                <p className="mt-3 flex items-start gap-2 text-[11.5px] leading-relaxed text-text-secondary">
                    <span
                        className={`mt-px shrink-0 ${
                            data.delivery === 'email' ? 'text-brand-primary' : 'text-status-warning'
                        }`}
                    >
                        <Icon name="info" size={14} />
                    </span>
                    {data.delivery === 'email'
                        ? 'We email the invitation to them. Its link also appears below, in case you would rather send it yourself.'
                        : 'Nothing is emailed from this server — no mail transport is configured. The invitation is recorded and its link appears below for you to send however you like.'}
                </p>
            </form>

            <section className="mt-5 overflow-hidden rounded-panel border border-border-default bg-bg-surface">
                <ul className="divide-y divide-border-divider">
                    <li className="flex items-center gap-3 px-5 py-3.5">
                        <Avatar name={data.owner.name} owner />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[13.5px] font-semibold text-text-primary">
                                {data.owner.name}
                            </p>
                            <p className="truncate text-[12px] text-text-secondary">
                                {data.owner.email}
                            </p>
                        </div>
                        <span className="rounded-badge bg-bg-inverse px-2.5 py-1 text-[10.5px] font-bold uppercase tracking-wide text-text-inverse">
                            Owner
                        </span>
                    </li>

                    {data.members.map((member) => (
                        <li key={member.id} className="px-5 py-3.5">
                            <div className="flex flex-wrap items-center gap-3">
                                <Avatar name={member.name ?? member.email} />

                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-[13.5px] font-medium text-text-primary">
                                        {member.name ?? member.email}
                                    </p>
                                    {member.name && (
                                        <p className="truncate text-[12px] text-text-secondary">
                                            {member.email}
                                        </p>
                                    )}
                                </div>

                                <select
                                    value={member.role}
                                    data-a={`team:role:${member.id}`}
                                    onChange={(event) => changeRole(member, event.target.value)}
                                    className="h-[32px] rounded-control border border-border-input px-2 text-[12.5px] capitalize focus:border-brand-primary focus:ring-0"
                                >
                                    {data.roles.map((option) => (
                                        <option key={option} value={option} className="capitalize">
                                            {option}
                                        </option>
                                    ))}
                                </select>

                                <Status member={member} />

                                {removing === member.id ? (
                                    <span className="flex items-center gap-2">
                                        <button
                                            data-a={`team:remove:cancel:${member.id}`}
                                            onClick={() => setRemoving(null)}
                                            className="text-[12px] text-text-secondary"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            data-a={`team:remove:confirm:${member.id}`}
                                            onClick={() => remove(member)}
                                            className="rounded-control bg-status-danger-fill px-2.5 py-1 text-[12px] font-semibold text-white"
                                        >
                                            Remove
                                        </button>
                                    </span>
                                ) : (
                                    <button
                                        data-a={`team:remove:${member.id}`}
                                        onClick={() => setRemoving(member.id)}
                                        className="text-[12px] text-text-secondary hover:text-status-danger"
                                    >
                                        Remove
                                    </button>
                                )}
                            </div>

                            {member.status === 'invited' && (
                                <div className="mt-2.5 flex flex-wrap items-center gap-2.5">
                                    {member.invite_live ? (
                                        <>
                                            <button
                                                data-a={`team:copy:${member.id}`}
                                                onClick={() => copyLink(member)}
                                                className="rounded-control bg-brand-primary-subtle px-2.5 py-1 text-[11.5px] font-semibold text-outline-active-text"
                                            >
                                                {copied === member.id ? 'Copied' : 'Copy invite link'}
                                            </button>
                                            <span className="text-[11.5px] text-text-secondary">
                                                sent {member.invited_at}
                                            </span>
                                        </>
                                    ) : (
                                        <>
                                            <span className="text-[11.5px] text-status-warning">
                                                The link has expired.
                                            </span>
                                            <button
                                                data-a={`team:resend:${member.id}`}
                                                onClick={() => resend(member)}
                                                className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                                            >
                                                Issue a new one
                                            </button>
                                        </>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

                {data.members.length === 0 && (
                    <p className="px-5 py-6 text-center text-[13px] text-text-secondary">
                        Nobody else yet. Invite a reader, an editor or a
                        reviewer above.
                    </p>
                )}
            </section>

            <p className="mt-4 text-[11.5px] leading-relaxed text-text-secondary">
                Editors can change the manuscript. Reviewers and viewers can
                read it. Only you can change settings, archive the book, or
                manage this list.
            </p>
        </div>
    );
}

function Avatar({ name, owner = false }) {
    const initials = (name ?? '?')
        .replace(/@.*/, '')
        .split(/[\s._-]+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <span
            className={`grid h-[34px] w-[34px] shrink-0 place-items-center rounded-full text-[12px] font-semibold ${
                owner ? 'bg-bg-inverse text-text-inverse' : 'bg-brand-fill text-white'
            }`}
        >
            {initials}
        </span>
    );
}

function Status({ member }) {
    const style =
        member.status === 'active'
            ? 'bg-status-success-bg text-status-success'
            : 'bg-status-warning-bg text-status-warning';

    return (
        <span
            className={`rounded-badge px-2.5 py-1 text-[10.5px] font-bold uppercase tracking-wide ${style}`}
        >
            {member.status === 'active' ? 'Active' : 'Invited'}
        </span>
    );
}
