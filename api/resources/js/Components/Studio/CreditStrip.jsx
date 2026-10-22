import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * The credit balance, above the studio.
 *
 * Here rather than only on the billing screen because this is where credits
 * are spent: an author deciding whether to run a whole-book review should be
 * able to see what it will leave them with, without navigating away to find
 * out.
 *
 * `balance` is the figure the server returned with the last review, so the
 * strip updates with the spend rather than lagging a request behind it.
 */
export default function CreditStrip({ balance }) {
    const [summary, setSummary] = useState(null);

    const load = useCallback(async () => {
        try {
            const response = await window.axios.get('/billing/summary');
            setSummary(response.data);
        } catch {
            // Silent: a balance that cannot be fetched should not put an
            // error in front of someone who was trying to write.
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    // A review returns the new balance, so take it rather than re-fetching.
    useEffect(() => {
        if (balance !== null && balance !== undefined) {
            setSummary((current) => (current ? { ...current, credits: balance } : current));
        }
    }, [balance]);

    if (!summary) return null;

    const {
        credits,
        monthly_credits: monthly,
        plan_name: planName,
        costs,
        reviews_simulated: simulated,
    } = summary;
    const low = monthly > 0 && credits < monthly * 0.15;

    return (
        <div
            data-a="credits:strip"
            className={`mb-[11px] flex flex-wrap items-center gap-x-3 gap-y-1 rounded-tile border px-3 py-2 text-[11.5px] ${
                low
                    ? 'border-status-danger/30 bg-status-danger-bg text-status-danger'
                    : 'border-border-default bg-bg-surface text-text-secondary'
            }`}
        >
            <span className={low ? 'font-semibold' : ''}>
                <b className="text-text-primary">{credits.toLocaleString()}</b> credits
                {monthly > 0 ? ` of ${monthly.toLocaleString()}` : ''} left
            </span>

            {simulated ? (
                <span data-a="credits:sample" className="hidden sm:inline">
                    Reviews here are sample output (automated checks, no AI) and
                    use no credits
                </span>
            ) : costs && (
                <span className="hidden sm:inline">
                    one editor {costs.agent_run} · specialist {costs.specialist_run} · whole
                    book {costs.book_review}
                </span>
            )}

            <button
                data-a="credits:billing"
                onClick={() => router.get(route('billing.show'))}
                className="ml-auto shrink-0 font-semibold text-brand-primary hover:underline"
            >
                {low ? `Top up · ${planName}` : 'Plans & billing'}
            </button>
        </div>
    );
}
