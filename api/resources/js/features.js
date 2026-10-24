import { usePage } from '@inertiajs/react';

/**
 * The modules switched on for this person (App\Platform\Features, shared
 * as `features` on every page). A module that is off is left out of the
 * navigation; its entry point refuses on the server as well.
 *
 * Anything not in the map counts as on, so a page rendered before the map
 * existed — or a key added later — never hides a working screen.
 */
export function useFeatures() {
    const features = usePage().props.features ?? {};

    return (key) => !key || features[key] !== false;
}

/** Navigation keys gated by a feature. */
export const NAV_FEATURES = {
    inbox: 'inbox',
    'store-sales': 'storefront',
    sales: 'storefront',
    support: 'support',
    preview: 'preview',
    audio: 'audiobook',
    course: 'course_builder',
    site: 'author_site',
};
