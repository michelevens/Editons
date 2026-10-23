import { useState } from 'react';
import Icon from './Icon';

/**
 * Reusable blocks an author can drop into a chapter.
 *
 * The templates are defined here rather than in the database because they are
 * part of the product, not the author's data — the same five exist for
 * everyone, and storing five identical rows per book would invite them to
 * drift. Author-defined templates would be a different feature with a table.
 *
 * Each returns ProseMirror JSON rather than HTML, because that is what the
 * editor stores. Generating HTML and parsing it back would be a lossy round
 * trip through a format nothing here uses.
 */

const paragraph = (text, marks) => ({
    type: 'paragraph',
    content: [{ type: 'text', text, ...(marks ? { marks } : {}) }],
});

const heading = (text, level = 2) => ({
    type: 'heading',
    attrs: { level },
    content: [{ type: 'text', text }],
});

const list = (items, ordered = false) => ({
    type: ordered ? 'orderedList' : 'bulletList',
    content: items.map((item) => ({
        type: 'listItem',
        content: [paragraph(item)],
    })),
});

export const TEMPLATES = [
    {
        id: 'takeaway',
        name: 'Key Takeaway',
        description: 'A highlighted summary box readers can skim.',
        icon: 'info',
        build: () => [
            {
                type: 'blockquote',
                content: [
                    paragraph('Key takeaway', [{ type: 'bold' }]),
                    paragraph(
                        'Write the one idea readers should remember from this section.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'case_study',
        name: 'Case study',
        description: 'A short story from practice, with a lesson.',
        icon: 'doc',
        build: () => [
            heading('Case study: [name]'),
            paragraph('Situation. Describe the person and the problem they faced.'),
            paragraph('What they did. Walk through the decision and the steps taken.'),
            paragraph('Result. Share the outcome and what a reader can apply.'),
        ],
    },
    {
        id: 'checklist',
        name: 'Checklist',
        description: 'A numbered list of concrete actions.',
        icon: 'check',
        build: () => [
            heading('Checklist'),
            list(['First action', 'Second action', 'Third action'], true),
        ],
    },
    {
        id: 'summary',
        name: 'Chapter summary',
        description: 'A closing recap with three key points.',
        icon: 'lib',
        build: () => [
            heading('Chapter summary'),
            list(['Key point one', 'Key point two', 'Key point three']),
        ],
    },
    {
        id: 'reflection',
        name: 'Reflection questions',
        description: 'Prompts that help a reader apply the chapter.',
        icon: 'chat',
        build: () => [
            heading('Questions for reflection'),
            list(
                [
                    'What would this change about your daily work?',
                    'Which part could you try first?',
                    'What is your biggest open question after this chapter?',
                ],
                true,
            ),
        ],
    },
];

export default function TemplatesPanel({ section, onInsert }) {
    const [inserted, setInserted] = useState(null);

    const insert = (template) => {
        onInsert?.(template.build());
        setInserted(template.id);
        window.setTimeout(() => setInserted(null), 2200);
    };

    return (
        <div className="mx-auto max-w-[760px] p-4 sm:p-8">
            <header className="mb-6">
                <h2 className="font-display text-[24px] font-bold tracking-[-0.03em] text-text-primary">
                    Templates
                </h2>
                <p className="mt-1.5 text-[14px] text-text-secondary">
                    {section
                        ? `Blocks to drop into ${section.title}.`
                        : 'Open a chapter to insert a block.'}
                </p>
            </header>

            <ul className="space-y-2.5">
                {TEMPLATES.map((template) => (
                    <li
                        key={template.id}
                        className="flex items-start gap-3.5 rounded-panel border border-border-default bg-bg-surface p-4"
                    >
                        <span className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-tile bg-brand-primary-subtle text-outline-active-text">
                            <Icon name={template.icon} size={17} />
                        </span>

                        <div className="min-w-0 flex-1">
                            <p className="text-[13.5px] font-semibold text-text-primary">
                                {template.name}
                            </p>
                            <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-secondary">
                                {template.description}
                            </p>
                        </div>

                        <button
                            data-a={`templates:insert:${template.id}`}
                            disabled={!section}
                            onClick={() => insert(template)}
                            title={section ? undefined : 'Open a chapter first.'}
                            className="h-[32px] shrink-0 rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {inserted === template.id ? 'Inserted' : 'Insert'}
                        </button>
                    </li>
                ))}
            </ul>

            <p className="mt-5 text-[11.5px] leading-relaxed text-text-secondary">
                A block is inserted at the end of the open chapter, as ordinary
                text you can edit. Nothing is locked, and the insertion saves
                like anything you type — so it is undoable.
            </p>
        </div>
    );
}
