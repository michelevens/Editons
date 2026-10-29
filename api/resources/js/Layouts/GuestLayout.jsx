import Icon from '@/Components/Studio/Icon';
import LanguageSwitch from '@/Components/LanguageSwitch';
import { useI18n } from '@/i18n';

/**
 * The signed-out shell: product pitch on the left, auth card on the right.
 *
 * Someone arriving at a sign-in screen may never have seen the marketing
 * site — a shared link, a bookmark, an invite. The left column is what tells
 * them what this is, so it carries the same claims the apex does and nothing
 * the product cannot do.
 *
 * Below 1024 the pitch is dropped rather than stacked above the form. On a
 * phone the person is there to sign in, and making them scroll past a sales
 * column to reach the field is a worse trade than losing the pitch.
 */
export default function GuestLayout({ children }) {
    const { t } = useI18n();

    return (
        <div className="relative min-h-screen overflow-hidden bg-bg-app font-ui text-text-body">
            {/* Two soft washes, as on the marketing page. Fixed and behind
                everything so they never intercept a click on the form. */}
            <div
                aria-hidden="true"
                className="pointer-events-none fixed -right-32 -top-40 h-[560px] w-[560px] rounded-full bg-brand-primary/[0.07] blur-3xl"
            />
            <div
                aria-hidden="true"
                className="pointer-events-none fixed -bottom-48 -left-40 h-[520px] w-[520px] rounded-full bg-status-success/[0.06] blur-3xl"
            />

            <div className="relative mx-auto grid min-h-screen max-w-[1240px] items-center gap-16 px-6 py-10 lg:grid-cols-[1.05fr_minmax(0,460px)] lg:py-16">
                <Pitch />

                <div className="w-full justify-self-center lg:justify-self-end">
                    {/* The logo sits above the card on small screens, where
                        the left column is gone and nothing else identifies
                        the product. */}
                    <a
                        href="https://ecrivain.ai"
                        className="mb-6 flex items-center gap-2.5 lg:hidden"
                    >
                        <LogoMark />
                        <span className="font-display text-[19px] font-bold tracking-[-0.02em] text-text-primary">
                            Écrivain
                        </span>
                    </a>

                    <div className="rounded-[20px] border border-border-default bg-bg-surface p-7 shadow-[0_24px_60px_-28px_rgba(15,23,42,0.28)] sm:p-9">
                        {children}
                    </div>

                    <p className="mt-6 text-center text-[12.5px] text-text-secondary">
                        <a href="https://ecrivain.ai" className="hover:text-text-primary">
                            ecrivain.ai
                        </a>
                        <span className="mx-2 text-border-input">·</span>
                        <a href="https://ecrivain.ai/#faq" className="hover:text-text-primary">
                            {t('guest.questions')}
                        </a>
                    </p>

                    {/* The language before signing in: whoever opens a shared
                        link in French or Kreyòl should not have to sign in
                        in English to find the switch. */}
                    <LanguageSwitch className="mt-3 flex justify-center" />
                </div>
            </div>
        </div>
    );
}

function LogoMark() {
    return (
        <span className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[10px] bg-gradient-to-br from-brand-fill to-[#7C6BF0] text-[16px] font-bold text-white shadow-[0_10px_24px_-12px_rgba(79,70,229,0.7)]">
            É
        </span>
    );
}

/**
 * The left column.
 *
 * Every claim here is one the product actually ships — seven agents,
 * provenance, cross-chapter consistency. Nothing about export, design or
 * publishing, which are not built.
 */
function Pitch() {
    const { t } = useI18n();
    const points = [t('guest.point_agents'), t('guest.point_library'), t('guest.point_consistency')];

    return (
        <div className="hidden lg:block">
            <a href="https://ecrivain.ai" className="mb-10 flex items-center gap-2.5">
                <LogoMark />
                <span className="font-display text-[20px] font-bold tracking-[-0.02em] text-text-primary">
                    Écrivain
                </span>
            </a>

            <span className="inline-flex items-center gap-2 rounded-full border border-border-default bg-bg-surface px-3.5 py-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-outline-active-text">
                <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                {t('guest.badge')}
            </span>

            <h1 className="mt-5 font-display text-[clamp(34px,4vw,48px)] font-extrabold leading-[1.06] tracking-[-0.035em] text-text-primary">
                {t('guest.headline_1')}
                <br />
                {t('guest.headline_2')}{' '}
                <span className="relative whitespace-nowrap text-brand-primary">
                    {t('guest.headline_3')}
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 200 12"
                        preserveAspectRatio="none"
                        className="absolute -bottom-1.5 left-0 h-2.5 w-full text-brand-primary-border"
                    >
                        <path
                            d="M2 8C40 3 90 2 130 5c25 2 45 3 68 1"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="4"
                            strokeLinecap="round"
                        />
                    </svg>
                </span>
            </h1>

            <p className="mt-6 max-w-[48ch] text-[17px] leading-relaxed text-text-secondary">
                {t('guest.lede')}
            </p>

            <ul className="mt-8 space-y-3.5">
                {points.map((point) => (
                    <li key={point} className="flex gap-3">
                        <span className="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-md bg-brand-primary-subtle text-outline-active-text">
                            <Icon name="check" size={13} strokeWidth={2.6} />
                        </span>
                        <span className="text-[14.5px] leading-relaxed text-text-body">
                            {point}
                        </span>
                    </li>
                ))}
            </ul>

            <div className="mt-8 flex flex-wrap gap-x-7 gap-y-2.5 text-[13px] text-text-secondary">
                <span className="flex items-center gap-2">
                    <span className="text-status-success">
                        <Icon name="check" size={14} strokeWidth={2.6} />
                    </span>
                    {t('guest.free_plan')}
                </span>
                <span className="flex items-center gap-2">
                    <span className="text-status-success">
                        <Icon name="check" size={14} strokeWidth={2.6} />
                    </span>
                    {t('guest.export_any_time')}
                </span>
            </div>

            <ReviewPreview />
        </div>
    );
}

/**
 * A still of the AI Editor panel.
 *
 * These are the real severities, agent names and finding shapes the product
 * produces — the same example the marketing page uses. It is labelled as an
 * example so it cannot be read as live data.
 */
function ReviewPreview() {
    const { t } = useI18n();
    const findings = [
        {
            severity: 'bg-status-danger',
            title: t('guest.finding_1_title'),
            detail: t('guest.finding_1_detail'),
            agent: t('guest.finding_1_agent'),
        },
        {
            severity: 'bg-status-warning',
            title: t('guest.finding_2_title'),
            detail: t('guest.finding_2_detail'),
            agent: t('guest.finding_2_agent'),
        },
    ];

    return (
        <div className="mt-10 max-w-[460px] overflow-hidden rounded-[16px] border border-border-default bg-bg-surface shadow-[0_24px_50px_-32px_rgba(15,23,42,0.35)]">
            <div className="flex items-center gap-2 border-b border-border-divider bg-bg-subtle px-4 py-2.5">
                <span className="h-2 w-2 rounded-full bg-border-default" />
                <span className="h-2 w-2 rounded-full bg-border-default" />
                <span className="h-2 w-2 rounded-full bg-border-default" />
                <span className="ml-1.5 text-[11px] font-medium text-text-secondary">
                    {t('guest.preview_title')}
                </span>
            </div>

            <div className="space-y-2 p-3.5">
                {findings.map((finding) => (
                    <div
                        key={finding.title}
                        className="flex gap-2.5 rounded-[10px] border border-border-default p-2.5"
                    >
                        <span
                            className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${finding.severity}`}
                        />
                        <div className="min-w-0">
                            <p className="text-[12.5px] font-semibold leading-snug text-text-primary">
                                {finding.title}
                            </p>
                            <p className="mt-0.5 text-[11.5px] leading-relaxed text-text-secondary">
                                {finding.detail}
                            </p>
                            <p className="mt-1 text-[10px] uppercase tracking-wide text-text-secondary">
                                {finding.agent}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="flex items-center justify-between border-t border-border-divider bg-bg-subtle px-4 py-2.5 text-[11px] text-text-secondary">
                <span>{t('guest.example_review')}</span>
                <span className="rounded-full bg-brand-primary-subtle px-2.5 py-1 font-semibold text-outline-active-text">
                    {t('guest.your_call')}
                </span>
            </div>
        </div>
    );
}
