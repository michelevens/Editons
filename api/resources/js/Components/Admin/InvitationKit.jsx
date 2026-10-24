/**
 * Shared by the two invitation pages (a page imported by another page is
 * folded into a shared chunk — see Kit.jsx — so these live here).
 */
export const STATE_TONES = { pending: 'info', accepted: 'success', expired: 'warning', revoked: 'neutral', declined: 'neutral' };

/** "Author × 6 mo · 500 credits" */
export function perkSummary(perks, plans = []) {
    const parts = [];
    if (perks?.plan) parts.push(`${plans.find((p) => p.key === perks.plan)?.name ?? perks.plan} × ${perks.plan_months} mo`);
    if (perks?.credits) parts.push(`${Number(perks.credits).toLocaleString()} credits`);

    return parts.join(' · ');
}
