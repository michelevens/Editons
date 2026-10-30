import KdpGuideView from '@/Components/Publish/KdpGuideView';
import { Head } from '@inertiajs/react';
import { useI18n } from '@/i18n';

/**
 * The KDP checklist: one guided page per book (and per KDP format), in
 * order, to work through on KDP with every value ready to paste. Opened for
 * one book from the Publish pane, or for many from the shelf or the
 * console's House catalog ("Prepare for publishing"). Printable.
 *
 * Amazon has no publishing API: nothing here touches KDP. The pace note is
 * informational, with no number: a third-party report of a weekly limit on
 * new titles could not be confirmed on an Amazon page.
 */
export default function KdpChecklist({ books, bulk, back, kdp_url: kdpUrl }) {
    const { t } = useI18n();

    return (
        <div className="min-h-dvh bg-bg-app px-4 py-6 font-ui text-text-body sm:px-8">
            <Head title={t('kdp.checklist')} />

            <div className="mx-auto max-w-[860px]">
                <header className="mb-5 flex flex-wrap items-center gap-3 print:hidden">
                    <a href={back} className="text-[12.5px] font-semibold text-brand-primary hover:underline">
                        {t('kdp.back')}
                    </a>
                    <h1 className="text-[20px] font-bold text-text-primary">{t('kdp.checklist')}</h1>
                    <div className="ml-auto flex gap-2">
                        <a
                            href={kdpUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            data-a="kdp:open"
                            className="rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover"
                        >
                            {t('kdp.open')}
                        </a>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="rounded-control border border-border-input px-3 py-2 text-[12.5px] font-medium text-text-body hover:border-brand-primary/40"
                        >
                            {t('kdp.print')}
                        </button>
                    </div>
                </header>

                <p className="mb-3 text-[12.5px] leading-relaxed text-text-secondary">
                    {t('kdp.intro')}
                </p>

                {bulk && (
                    <p role="note" data-a="kdp:pace" className="mb-4 rounded-tile border border-status-info/30 bg-status-info-bg px-3 py-2 text-[12.5px] text-status-info">
                        {t('kdp.pace')}
                    </p>
                )}

                <ol className="space-y-8">
                    {books.map((book, index) => (
                        <li key={book.id} className="break-before-page" data-a={`kdp:book:${book.id}`}>
                            <h2 className="mb-2 flex flex-wrap items-baseline gap-2 text-[16px] font-semibold text-text-primary">
                                <span className="text-text-secondary">{index + 1}.</span> {book.title}
                                <a href={book.studio} className="text-[12px] font-medium text-brand-primary hover:underline print:hidden">
                                    {t('kdp.open_studio')}
                                </a>
                            </h2>
                            {book.guides.length === 0 ? (
                                <p className="text-[12.5px] text-text-secondary">
                                    {t('kdp.not_chosen')}
                                </p>
                            ) : (
                                <div className="space-y-5">
                                    {book.guides.map((guide) => (
                                        <div key={guide.format}>
                                            <h3 className="mb-2 text-[13.5px] font-semibold text-text-primary">
                                                {guide.label}
                                                {guide.status === 'fail' && (
                                                    <span className="ml-2 text-[12px] font-medium text-status-danger">{t('kdp.fix_first')}</span>
                                                )}
                                            </h3>
                                            <KdpGuideView guide={guide} idPrefix={`kdp-${book.id}`} />
                                        </div>
                                    ))}
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
            </div>
        </div>
    );
}
