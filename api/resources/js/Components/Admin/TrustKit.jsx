import { inputClass } from '@/Layouts/AdminLayout';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Small pieces shared by the Trust & operations pages: filter chips, a
 * search box that submits on Enter, and the tones for states.
 */

export const STATE_TONES = {
    ok: 'success',
    operational: 'success',
    warning: 'warning',
    degraded: 'warning',
    failing: 'danger',
    partial_outage: 'danger',
    major_outage: 'danger',
    maintenance: 'info',
    unknown: 'neutral',
    unavailable: 'neutral',
};

export const STATE_LABELS = {
    operational: 'Operational',
    degraded: 'Degraded',
    partial_outage: 'Partial outage',
    major_outage: 'Major outage',
    maintenance: 'Maintenance',
    unknown: 'Unknown',
    unavailable: 'Not offered',
};

export function Chips({ label, options, value, onChange, dataA }) {
    return (
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={label}>
            {options.map(([key, text]) => (
                <button
                    key={key || 'all'}
                    type="button"
                    data-a={dataA ? `${dataA}:${key || 'all'}` : undefined}
                    onClick={() => onChange(key)}
                    aria-pressed={value === key}
                    className={`tap rounded-control px-3 py-2 text-[12.5px] ${
                        value === key ? 'bg-bg-inverse text-text-inverse' : 'bg-bg-surface text-text-secondary hover:text-text-primary'
                    }`}
                >
                    {text}
                </button>
            ))}
        </div>
    );
}

/** Search on Enter (or the button), keeping the other filters. */
export function SearchBox({ routeName, params, value, placeholder, dataA }) {
    const [q, setQ] = useState(value ?? '');

    return (
        <form
            role="search"
            className="flex min-w-0 flex-1 gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                router.get(route(routeName), { ...params, q: q || undefined }, { preserveState: true });
            }}
        >
            <label className="sr-only" htmlFor={`${dataA}-input`}>
                {placeholder}
            </label>
            <input
                id={`${dataA}-input`}
                data-a={dataA}
                value={q}
                onChange={(event) => setQ(event.target.value)}
                placeholder={placeholder}
                className={`${inputClass} min-w-0`}
            />
            <button type="submit" className="tap rounded-control bg-bg-inverse px-3 text-[12.5px] font-semibold text-text-inverse">
                Search
            </button>
        </form>
    );
}

export function bytes(n) {
    if (n === null || n === undefined) return 'unknown size';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let v = n;
    while (v >= 1024 && i < units.length - 1) {
        v /= 1024;
        i += 1;
    }
    return `${i === 0 ? v : v.toFixed(1)} ${units[i]}`;
}
