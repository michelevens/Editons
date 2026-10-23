import { router } from '@inertiajs/react';

/**
 * Where a destination opens inside the book editor.
 *
 * One map for every page. The header, the sidebar, the Overview checklist and
 * the search palette all name destinations by key; before this, each page
 * translated keys on its own, and most of them translated everything that was
 * not "My Books" into the manuscript.
 *
 * Top-nav areas (ai, analytics, design...) are a `nav`; sidebar screens are a
 * `pane` under the writing area.
 */
export function destination(key) {
    switch (key) {
        case 'ai':
        case 'suggestions':
            return { nav: 'ai', pane: 'manuscript' };
        case 'analytics':
            return { nav: 'analytics', pane: 'manuscript' };
        case 'research':
            return { nav: 'research', pane: 'research' };
        case 'writing':
            return { nav: 'writing', pane: 'manuscript' };
        case 'design':
            return { nav: 'design', pane: 'layout' };
        case 'publishing':
            return { nav: 'publishing', pane: 'export' };
        // The full-screen book preview, opened from Export and Design.
        case 'preview':
            return { nav: 'publishing', pane: 'preview' };
        case 'sales':
            return { nav: 'sales', pane: 'distribution' };
        default:
            return { nav: 'writing', pane: key ?? 'manuscript' };
    }
}

/**
 * Go to a destination from a page that is not the editor.
 *
 * Overview and Book Settings are pages of their own; everything else is a
 * pane of the editor, reached with ?pane=. Without a book there is nowhere
 * for a book screen to open, so the shelf is the answer.
 */
export function goTo(bookId, key) {
    if (key === 'books' || !bookId) {
        router.get(route('books.index'));

        return;
    }

    if (key === 'overview') {
        router.get(route('books.overview', bookId));

        return;
    }

    if (key === 'settings') {
        router.get(route('books.settings', bookId));

        return;
    }

    // The data argument, not options.data: Inertia's get() overwrites
    // options.data with its second argument, which is how the pane used to
    // disappear and every Overview link opened the manuscript.
    router.get(route('books.edit', bookId), { pane: key });
}
