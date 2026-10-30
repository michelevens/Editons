import { Extension, InputRule } from '@tiptap/core';

/**
 * The book's typography rules as you type (ADR — Book language and
 * typography): curly quotes or « guillemets », French spacing before
 * ; ! ? : and inside « », the em dash for -- and the ellipsis for ...
 *
 * Only installed when the book turns "Correct as you type" on. Each
 * correction is an input rule, so it is its own step in the history:
 * Ctrl/Cmd+Z (or Backspace straight after) puts back what was typed and
 * keeps it. Never inside code, which input rules skip.
 *
 * The whole-book pass and the renderers use the same rules on the server
 * (App\Editorial\Style\Typography), where they can look ahead; typed, a
 * colon is only spaced when a space was typed before it, since "10:30"
 * and "https:" are written the same way up to that point.
 */

export const NBSP = ' ';
export const NNBSP = ' ';

/** Characters after which a quote opens rather than closes. */
const OPENS = /[\s  ([{“‘«—–/-]/;

/** A character French spacing goes after: a word, a number, a closing mark. */
const SPACED = /[\p{L}\p{N}»”’)\]…]/u;

/** The character before a match, or '' at the start of the paragraph. */
const before = (match) => (match.index > 0 ? match.input[match.index - 1] : '');

/**
 * A rule replacing the whole match with what `replace` returns; null
 * leaves the typing alone.
 */
const rule = (find, replace) =>
    new InputRule({
        find,
        handler: ({ state, range, match }) => {
            const text = replace(match);

            if (text === null || text === match[0]) return null;

            state.tr.insertText(text, range.from, range.to);

            return undefined;
        },
    });

/** The rules for one set of choices; exported for the tests. */
export function typographyRules({ quotes = 'none', french_spacing: french = false, dashes = false, ellipsis = false }) {
    const rules = [];

    if (ellipsis) rules.push(rule(/(?<!\.)\.\.\.$/, () => '…'));

    if (dashes) {
        rules.push(rule(/(?<!-)--$/, () => '—'));
        // A hyphen typed with a space each side is a dash between clauses.
        rules.push(rule(/(?<=[  ])- $/, () => '– '));
    }

    if (quotes !== 'none') {
        rules.push(rule(/( *)"$/, (m) => {
            const opening = m[1] !== '' || before(m) === '' || OPENS.test(before(m));

            if (quotes === 'curly') return m[1] + (opening ? '“' : '”');
            if (opening) return m[1] + '«' + (french ? NBSP : '');

            return (french ? NBSP : '') + '»';
        }));
        rules.push(rule(/'$/, (m) => (before(m) === '' || OPENS.test(before(m)) ? '‘' : '’')));
    }

    if (french) {
        rules.push(rule(/( *)([;!?])$/, (m) => {
            if (m[1] !== '') return NNBSP + m[2];
            if (before(m) === NBSP) return null;

            return SPACED.test(before(m)) ? NNBSP + m[2] : null;
        }));
        // A no-break space before ; ! ? typed earlier becomes a narrow one.
        rules.push(rule(/ ([;!?])$/, (m) => NNBSP + m[1]));
        rules.push(rule(/( +):$/, () => NBSP + ':'));
        rules.push(rule(/( *)»$/, (m) => {
            if (m[1] !== '') return NBSP + '»';

            return before(m) !== '' && !/[\s  ]/.test(before(m)) ? NBSP + '»' : null;
        }));
        // The first character typed inside « gets its space.
        rules.push(rule(/«( *)([^\s  ])$/u, (m) => '«' + NBSP + m[2]));
    }

    return rules;
}

export const Typography = Extension.create({
    name: 'bookTypography',

    addOptions() {
        return { quotes: 'none', french_spacing: false, dashes: false, ellipsis: false };
    },

    addInputRules() {
        return typographyRules(this.options);
    },
});
