import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * A screen that is in the product but not built yet.
 *
 * Hiding these would make the product impossible to judge; faking them would
 * misrepresent it. So each says what it would do, what it still needs, and
 * which plan it belongs to — and does not pretend the only thing standing in
 * the way is money, when the honest answer is that it is not written.
 */
export default function LockedPanel({ bookId, screen, icon = 'lock' }) {
    const [data, setData] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/coming-soon/${screen}`);
        setData(response.data);
    }, [bookId, screen]);

    useEffect(() => {
        load();
    }, [load]);

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    return (
        <div className="flex h-full items-center justify-center p-8">
            <div
                data-a={`locked:${screen}`}
                className="max-w-[460px] text-center"
            >
                <span className="inline-flex text-text-secondary">
                    <Icon name={icon} size={34} strokeWidth={1.5} />
                </span>

                <h2 className="mt-3 font-display text-[20px] font-bold tracking-[-0.02em] text-text-primary">
                    {data.title}
                </h2>

                <p className="mt-2 text-[13px] leading-relaxed text-text-body">
                    {data.summary}
                </p>

                <p className="mt-3 rounded-tile bg-bg-subtle px-3 py-2.5 text-[11.5px] leading-relaxed text-text-secondary">
                    Not built yet. It still needs {data.needs.charAt(0).toLowerCase()}
                    {data.needs.slice(1)}
                </p>

                <p className="mt-3 text-[11.5px] text-text-secondary">
                    {data.entitled
                        ? `It will be part of your ${data.plan.name} plan when it lands.`
                        : `It will be part of the ${data.plan.name} plan, at $${data.plan.price} a month.`}
                </p>

                {!data.entitled && (
                    <button
                        data-a={`locked:plans:${screen}`}
                        onClick={() => router.get(route('billing.show'))}
                        className="mt-3 rounded-control border border-border-input px-3 py-1.5 text-[12px] font-medium text-text-body transition hover:border-brand-primary/40"
                    >
                        Compare plans
                    </button>
                )}
            </div>
        </div>
    );
}
