import { Extension } from '@tiptap/core';
import { useEffect, useState } from 'react';

/**
 * The kind of box a quotation is: an explicit choice stored on the
 * blockquote as attrs.callout, which the renderers read before anything
 * else (Textbook::callout). Null leaves it to the label's wording, which is
 * how imported books and existing content keep their boxes; "quote" makes
 * it a quotation whatever its label says.
 *
 * An attribute rather than a node of its own, so every existing quotation,
 * Markdown round trip and suggestion keeps working unchanged.
 */
export const CALLOUT_CHOICES = [
    { key: '', name: 'Box: from its label' },
    { key: 'quote', name: 'Quotation (no box)' },
    { key: 'note', name: 'Note' },
    { key: 'key', name: 'Key point' },
    { key: 'pitfall', name: 'Caution' },
    { key: 'method', name: 'Method' },
    { key: 'tip', name: 'Tip' },
    { key: 'example', name: 'Example' },
    { key: 'check', name: 'Check yourself' },
    { key: 'plain', name: 'Plain box' },
];

const KEYS = CALLOUT_CHOICES.map((choice) => choice.key).filter(Boolean);

export const CalloutChoice = Extension.create({
    name: 'calloutChoice',

    addGlobalAttributes() {
        return [
            {
                types: ['blockquote'],
                attributes: {
                    callout: {
                        default: null,
                        parseHTML: (element) => {
                            const value = element.getAttribute('data-callout');

                            return KEYS.includes(value) ? value : null;
                        },
                        renderHTML: (attributes) => (attributes.callout ? { 'data-callout': attributes.callout } : {}),
                    },
                },
            },
        ];
    },

    addCommands() {
        return {
            setCallout:
                (value) =>
                ({ commands }) =>
                    commands.updateAttributes('blockquote', { callout: KEYS.includes(value) ? value : null }),
        };
    },
});

/**
 * The book's box styles as CSS for the editor (DesignController::callouts),
 * from the same table the PDF and the EPUB are set from.
 */
export function useCalloutCss(bookId) {
    const [css, setCss] = useState('');

    useEffect(() => {
        if (!bookId) return undefined;

        let live = true;

        window.axios
            .get(`/books/${bookId}/design/callouts`)
            .then((response) => live && setCss(response.data.css ?? ''))
            .catch(() => null);

        return () => {
            live = false;
        };
    }, [bookId]);

    return css;
}
