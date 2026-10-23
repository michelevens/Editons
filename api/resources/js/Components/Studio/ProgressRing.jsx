/**
 * A word-goal ring: how far `value` is toward `goal`.
 *
 * An SVG circle whose stroke is drawn to the share reached, capped at a
 * full circle. At 100% it turns the success colour and carries a check —
 * "Goal reached", said once and quietly, not celebrated.
 *
 * Decorative by default (the caller names it, as a button label or in its
 * text); `label` makes it an image with its own accessible name.
 */
export function progressOf(value, goal) {
    if (!goal || goal <= 0) return 0;

    return Math.max(0, Math.min(1, (Number(value) || 0) / goal));
}

export function ringLabel(value, goal) {
    const words = (Number(value) || 0).toLocaleString();

    if (!goal) return `${words} words, no goal set`;

    return progressOf(value, goal) >= 1
        ? `Goal reached: ${words} of ${goal.toLocaleString()} words`
        : `${words} of ${goal.toLocaleString()} words, ${Math.floor(progressOf(value, goal) * 100)}%`;
}

export default function ProgressRing({ value, goal, size = 22, stroke = 3, label = null, className = '' }) {
    const share = progressOf(value, goal);
    const reached = goal > 0 && share >= 1;
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;

    return (
        <svg
            width={size}
            height={size}
            viewBox={`0 0 ${size} ${size}`}
            data-reached={reached ? 'true' : undefined}
            data-progress={Math.floor(share * 100)}
            role={label ? 'img' : undefined}
            aria-label={label ?? undefined}
            aria-hidden={label ? undefined : 'true'}
            className={`shrink-0 ${className}`}
        >
            <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                strokeWidth={stroke}
                className="stroke-border-default"
            />
            {share > 0 && (
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={circumference * (1 - share)}
                    transform={`rotate(-90 ${size / 2} ${size / 2})`}
                    className={`${reached ? 'stroke-status-success' : 'stroke-brand-primary'} transition-[stroke-dashoffset] duration-500`}
                />
            )}
            {reached && size >= 18 && (
                <path
                    d={`M${size * 0.32} ${size * 0.52} l${size * 0.12} ${size * 0.12} l${size * 0.24} -${size * 0.26}`}
                    fill="none"
                    strokeWidth={Math.max(1.5, stroke * 0.7)}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="stroke-status-success"
                />
            )}
        </svg>
    );
}
