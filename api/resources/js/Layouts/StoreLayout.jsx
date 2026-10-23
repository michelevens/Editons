/**
 * The public storefront's shell.
 *
 * Signed-out readers land here from a shared link. The brand is the store's
 * — "Écrivain" — and never the account's: the only name a page prints is the
 * book's own author name, and only when it has one.
 */
export default function StoreLayout({ children }) {
    return (
        <div className="min-h-screen bg-bg-app font-ui text-text-body">
            <header className="border-b border-border-default bg-bg-surface">
                <div className="mx-auto flex h-[54px] max-w-[1040px] items-center gap-2.5 px-4 sm:px-6">
                    <img src="/brand/symbol.svg" alt="" width={24} height={24} className="shrink-0" />
                    <span className="font-display text-[18px] font-bold tracking-[-0.01em] text-text-primary">
                        Écrivain
                    </span>
                    <span className="ml-1 text-[12px] text-text-secondary">Bookstore</span>
                </div>
            </header>

            <main className="mx-auto max-w-[1040px] px-4 py-8 sm:px-6 sm:py-10">{children}</main>

            <footer className="mx-auto max-w-[1040px] px-4 pb-10 text-[11.5px] text-text-secondary sm:px-6">
                Payments are processed by Stripe. Écrivain never sees your card number.
            </footer>
        </div>
    );
}
