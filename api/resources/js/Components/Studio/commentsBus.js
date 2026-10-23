/**
 * Comment threads shared between the places that show them.
 *
 * Every comment endpoint answers with the book's whole thread list, so
 * whoever posts, replies or resolves announces that list here, and the
 * Comments panel and the highlights in the manuscript both take it. One
 * event rather than a prop threaded through the studio: the two live in
 * different branches of the page (and the panel sometimes in a drawer).
 */
const EVENT = 'ecrivain:comments';

export function publishComments(data) {
    if (data) window.dispatchEvent(new CustomEvent(EVENT, { detail: data }));
}

/** Subscribe; returns the unsubscribe function, for an effect's cleanup. */
export function onComments(handler) {
    const listener = (event) => handler(event.detail);

    window.addEventListener(EVENT, listener);

    return () => window.removeEventListener(EVENT, listener);
}
