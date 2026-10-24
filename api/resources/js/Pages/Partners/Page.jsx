import PartnerPageView, { CHROME } from '@/Components/Partners/PartnerPageView';
import StoreLayout from '@/Layouts/StoreLayout';
import { Head } from '@inertiajs/react';

/**
 * A partner's public page, /p/{handle}. The server decides what may be
 * shown: the live page, the partner's own preview before approval, or a
 * neutral "not available" (page null) that says nothing about why. The
 * meta tags a link preview needs are in the HTML from the server
 * (app.blade.php); these repeat them for the browser tab.
 */
export default function Page({ page, preview, live }) {
    if (!page) {
        const t = CHROME.en;

        return (
            <StoreLayout>
                <Head title={t.unavailable}>
                    <meta name="robots" content="noindex, nofollow" />
                </Head>
                <div data-a="partner:unavailable" className="mx-auto max-w-[520px] rounded-panel border border-border-default bg-bg-surface p-6 text-center">
                    <h1 className="font-display text-[22px] font-extrabold text-text-primary">{t.unavailable}</h1>
                    <p className="mt-2 text-[13.5px] text-text-secondary">{t.unavailableDetail}</p>
                </div>
            </StoreLayout>
        );
    }

    return (
        <StoreLayout>
            <Head title={`${page.name} · Écrivain Partner`}>
                {!live && <meta name="robots" content="noindex, nofollow" />}
            </Head>
            <PartnerPageView page={page} preview={preview} interactive={live} />
        </StoreLayout>
    );
}
