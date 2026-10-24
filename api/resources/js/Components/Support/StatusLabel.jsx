/** Where a support request stands, in the author's words. */
export default function StatusLabel({ status }) {
    const text = { open: 'Waiting for support', pending: 'Answered', closed: 'Closed' }[status] ?? status;
    const tone = { open: 'bg-status-warning-bg text-status-warning', pending: 'bg-status-success-bg text-status-success', closed: 'bg-bg-subtle text-text-secondary' }[status];

    return <span className={`shrink-0 rounded-badge px-2 py-0.5 text-[11.5px] font-medium ${tone}`}>{text}</span>;
}
