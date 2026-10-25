import KdpGuideView from '@/Components/Publish/KdpGuideView';
import { Head } from '@inertiajs/react';

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
    return (
        <div className="min-h-dvh bg-bg-app px-4 py-6 font-ui text-text-body sm:px-8">
            <Head title="KDP checklist" />

            <div className="mx-auto max-w-[860px]">
                <header className="mb-5 flex flex-wrap items-center gap-3 print:hidden">
                    <a href={back} className="text-[12.5px] font-semibold text-brand-primary hover:underline">
                        ← Back
                    </a>
                    <h1 className="text-[20px] font-bold text-text-primary">KDP checklist</h1>
                    <div className="ml-auto flex gap-2">
                        <a
                            href={kdpUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            data-a="kdp:open"
                            className="rounded-control bg-brand-fill px-3 py-2 text-[12.5px] font-semibold text-white hover:bg-brand-primary-hover"
                        >
                            Open KDP
                        </a>
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="rounded-control border border-border-input px-3 py-2 text-[12.5px] font-medium text-text-body hover:border-brand-primary/40"
                        >
                            Print
                        </button>
                    </div>
                </header>

                <p className="mb-3 text-[12.5px] leading-relaxed text-text-secondary">
                    For each book: on your KDP Bookshelf, create a Kindle eBook or a Paperback and fill in its three pages with the
                    values below — each has a Copy button. Upload the files from the download links. Then come back to the book&rsquo;s
                    Publish screen and enter the ASIN under &ldquo;I&rsquo;ve submitted it&rdquo;.
                </p>

                {bulk && (
                    <p role="note" data-a="kdp:pace" className="mb-4 rounded-tile border border-status-info/30 bg-status-info-bg px-3 py-2 text-[12.5px] text-status-info">
                        Amazon may limit how many new titles you can add each week — check KDP&rsquo;s help pages before adding many at once.
                    </p>
                )}

                <ol className="space-y-8">
                    {books.map((book, index) => (
                        <li key={book.id} className="break-before-page" data-a={`kdp:book:${book.id}`}>
                            <h2 className="mb-2 flex flex-wrap items-baseline gap-2 text-[16px] font-semibold text-text-primary">
                                <span className="text-text-secondary">{index + 1}.</span> {book.title}
                                <a href={book.studio} className="text-[12px] font-medium text-brand-primary hover:underline print:hidden">
                                    Open in the studio
                                </a>
                            </h2>
                            {book.guides.length === 0 ? (
                                <p className="text-[12.5px] text-text-secondary">
                                    Amazon KDP is not chosen for this book. Choose it in step 2 of Publish.
                                </p>
                            ) : (
                                <div className="space-y-5">
                                    {book.guides.map((guide) => (
                                        <div key={guide.format}>
                                            <h3 className="mb-2 text-[13.5px] font-semibold text-text-primary">
                                                {guide.label}
                                                {guide.status === 'fail' && (
                                                    <span className="ml-2 text-[12px] font-medium text-status-danger">Fix the ✕ items first</span>
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
