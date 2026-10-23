import { useEffect } from 'react';
import Dialog, { CloseButton } from '../Dialog';
import { TOP_NAV } from './AppHeader';
import BookSidebar from './BookSidebar';

/**
 * The book sidebar, static at 1024px and wider, a drawer below.
 *
 * Overview and Book Settings rendered BookSidebar on its own, so at phone
 * width the 223px column stayed on screen and squeezed the page to about
 * 130px. Every page with a book sidebar now goes through this, the way the
 * editor already behaved.
 *
 * The drawer is a real dialog (focus moves in, Tab stays in, Escape and a
 * visible close button close it, focus returns to the Menu button) and it
 * also lists the header's areas: the header hides its nav below 1024px, which
 * left Analytics with no way in.
 */
export default function StudioSidebar({
    open,
    onClose,
    activeNav = null,
    onSelectNav,
    ...sidebar
}) {
    // Grown past the breakpoint with the drawer open: the static column is
    // back, so the drawer would be a second copy over it.
    useEffect(() => {
        if (!open) return undefined;

        const wide = window.matchMedia('(min-width: 1024px)');
        const onChange = () => wide.matches && onClose();

        wide.addEventListener('change', onChange);

        return () => wide.removeEventListener('change', onChange);
    }, [open, onClose]);

    return (
        <>
            {/* Not rendered while the drawer is open (it only opens below
                1024px, where this copy is hidden anyway), so the page holds
                one sidebar at a time. */}
            {!open && (
                <div className="flex min-h-0 max-lg:hidden">
                    <BookSidebar {...sidebar} />
                </div>
            )}

            {open && (
                <Dialog
                    variant="drawer"
                    label="Menu"
                    onClose={onClose}
                    data-a="drawer:menu"
                    className="flex h-full max-w-[86vw] flex-col bg-bg-sidebar shadow-popover lg:hidden"
                >
                    <div className="flex items-center justify-between px-[18px] pt-2.5">
                        <span className="text-[11px] font-medium tracking-[1.6px] text-text-sidebar-muted">
                            MENU
                        </span>
                        <CloseButton
                            onClick={onClose}
                            label="Close menu"
                            className="text-text-sidebar hover:bg-bg-sidebar-active hover:text-white"
                        />
                    </div>

                    <BookSidebar
                        {...sidebar}
                        className="min-h-0 flex-1"
                        topNav={TOP_NAV}
                        activeNav={activeNav}
                        onSelectTop={(item) => {
                            onClose();
                            onSelectNav?.(item);
                        }}
                        onSelect={(item) => {
                            onClose();
                            sidebar.onSelect?.(item);
                        }}
                    />
                </Dialog>
            )}
        </>
    );
}
