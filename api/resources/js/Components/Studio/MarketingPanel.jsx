import { useCallback, useEffect, useState } from 'react';
import Icon from './Icon';

/**
 * The Marketing Kit.
 *
 * The copy is assembled from the author's own sentences rather than written
 * fresh — this goes out under their name to people deciding whether to buy,
 * and an invented claim in a store listing is their problem, not ours. The
 * screen says so, because an author should know what they are about to send.
 */
export default function MarketingPanel({ bookId, onNotify }) {
    const [data, setData] = useState(null);
    const [audience, setAudience] = useState('');
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(null);

    const load = useCallback(async () => {
        const response = await window.axios.get(`/books/${bookId}/marketing-kit`);
        setData(response.data);
        setAudience(response.data.kit?.audience ?? '');
    }, [bookId]);

    useEffect(() => {
        load();
    }, [load]);

    const generate = async () => {
        setBusy(true);

        try {
            const response = await window.axios.post(`/books/${bookId}/marketing-kit`, {
                audience: audience || null,
            });

            setData(response.data);
            setAudience(response.data.kit?.audience ?? '');
        } catch (error) {
            onNotify?.(
                error.response?.data?.errors?.credits?.[0]
                    ?? error.response?.data?.message
                    ?? 'The kit could not be generated.',
            );
        } finally {
            setBusy(false);
        }
    };

    const copy = async (key, text) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(key);
            setTimeout(() => setCopied(null), 1600);
        } catch {
            onNotify?.('Could not reach the clipboard. Select the text and copy it.');
        }
    };

    if (!data) {
        return <p className="p-6 text-center text-[12px] text-text-secondary">Loading…</p>;
    }

    const { kit, cost, has_chapters: hasChapters } = data;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <header className="flex flex-wrap items-center gap-3 border-b border-border-default px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text-primary">Marketing Kit</h2>
                    <p className="text-[11.5px] text-text-secondary">
                        Launch copy built from your manuscript
                    </p>
                </div>

                <button
                    data-a="kit:generate"
                    disabled={busy || !hasChapters}
                    onClick={generate}
                    className="ml-auto rounded-control bg-brand-primary px-3 py-2 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:opacity-50"
                >
                    {busy ? 'Building…' : `${kit ? 'Rebuild' : 'Build kit'} · ${cost} credits`}
                </button>
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-auto p-4">
                <label className="block rounded-tile border border-border-default bg-bg-surface p-3.5">
                    <span className="mb-1 block text-[12px] font-medium text-text-primary">
                        Who is this book for?
                    </span>
                    <input
                        value={audience}
                        data-a="kit:audience"
                        placeholder="Left blank, it is taken from your category"
                        onChange={(event) => setAudience(event.target.value)}
                        className="w-full rounded-control border border-border-input px-2 py-1.5 text-[12.5px] focus:border-brand-primary focus:ring-0"
                    />
                </label>

                {!hasChapters && (
                    <p className="rounded-tile bg-status-warning-bg px-3 py-2 text-[11.5px] text-status-warning">
                        Write a chapter first. The copy is made from your own words, so there
                        has to be something to work from.
                    </p>
                )}

                {kit ? (
                    <>
                        <Block
                            title="Store description"
                            dataA="kit:description"
                            copied={copied === 'description'}
                            onCopy={() => copy('description', kit.description)}
                        >
                            <p className="whitespace-pre-wrap font-manuscript text-[13px] leading-relaxed text-text-body">
                                {kit.description}
                            </p>
                        </Block>

                        <Block
                            title="Launch email"
                            dataA="kit:email"
                            copied={copied === 'email'}
                            onCopy={() => copy('email', `${kit.email.subject}\n\n${kit.email.body}`)}
                        >
                            <p className="text-[12px] text-text-primary">
                                <b>Subject:</b> {kit.email.subject}
                            </p>
                            <p className="mt-1.5 whitespace-pre-wrap font-manuscript text-[13px] leading-relaxed text-text-body">
                                {kit.email.body}
                            </p>
                        </Block>

                        <section className="rounded-tile border border-border-default bg-bg-surface p-3.5">
                            <h3 className="mb-2 text-[13px] font-semibold text-text-primary">
                                Short posts
                            </h3>

                            <ul className="space-y-2">
                                {kit.posts.map((post, index) => (
                                    <li
                                        key={index}
                                        className="rounded-tile border border-border-default p-2.5"
                                    >
                                        <p className="whitespace-pre-wrap text-[12.5px] leading-relaxed text-text-body">
                                            {post}
                                        </p>
                                        <div className="mt-1.5 flex items-center gap-3">
                                            <span className="text-[10.5px] text-text-secondary">
                                                {post.length} characters
                                            </span>
                                            <button
                                                data-a={`kit:copy-post:${index}`}
                                                onClick={() => copy(`post:${index}`, post)}
                                                className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                                            >
                                                {copied === `post:${index}` ? 'Copied' : 'Copy'}
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <p className="rounded-tile bg-bg-subtle px-3 py-2.5 text-[11px] leading-relaxed text-text-secondary">
                            Every line above is drawn from your own writing and rearranged —
                            nothing here makes a claim your manuscript does not. Read it before
                            you send it: it goes out under your name.
                        </p>
                    </>
                ) : (
                    hasChapters && (
                        <div className="rounded-tile border border-border-default bg-bg-surface p-8 text-center">
                            <span className="inline-flex text-text-secondary/60">
                                <Icon name="mega" size={30} strokeWidth={1.5} />
                            </span>
                            <p className="mt-2 text-[13px] font-medium text-text-primary">
                                Build your launch copy
                            </p>
                            <p className="mt-1 text-[11.5px] leading-relaxed text-text-secondary">
                                A store description, a launch email and short posts, assembled
                                from the chapters you have written.
                            </p>
                        </div>
                    )
                )}
            </div>
        </div>
    );
}

function Block({ title, children, onCopy, copied, dataA }) {
    return (
        <section
            data-a={dataA}
            className="rounded-tile border border-border-default bg-bg-surface p-3.5"
        >
            <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold text-text-primary">{title}</h3>
                <button
                    onClick={onCopy}
                    className="text-[11.5px] font-semibold text-brand-primary hover:underline"
                >
                    {copied ? 'Copied' : 'Copy'}
                </button>
            </div>
            {children}
        </section>
    );
}
