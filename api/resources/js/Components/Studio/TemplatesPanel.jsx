import { useState } from 'react';
import { t, useI18n } from '../../i18n';
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
        tKey: 'templates.takeaway',
        blurbKey: 'templates.takeaway_blurb',
        icon: 'info',
        build: () => [
            {
                type: 'blockquote',
                content: [
                    paragraph(t('templates.takeaway_heading'), [{ type: 'bold' }]),
                    paragraph(
                        t('templates.takeaway_text'),
                    ),
                ],
            },
        ],
    },
    {
        id: 'case_study',
        tKey: 'templates.case_study',
        blurbKey: 'templates.case_study_blurb',
        icon: 'doc',
        build: () => [
            heading(t('templates.case_study_heading')),
            paragraph(t('templates.case_study_situation')),
            paragraph(t('templates.case_study_action')),
            paragraph(t('templates.case_study_result')),
        ],
    },
    {
        id: 'checklist',
        tKey: 'templates.checklist',
        blurbKey: 'templates.checklist_blurb',
        icon: 'check',
        build: () => [
            heading(t('templates.checklist')),
            list([t('templates.checklist_first'), t('templates.checklist_second'), t('templates.checklist_third')], true),
        ],
    },
    {
        id: 'summary',
        tKey: 'templates.summary',
        blurbKey: 'templates.summary_blurb',
        icon: 'lib',
        build: () => [
            heading(t('templates.summary')),
            list([t('templates.summary_one'), t('templates.summary_two'), t('templates.summary_three')]),
        ],
    },
    {
        id: 'reflection',
        tKey: 'templates.reflection',
        blurbKey: 'templates.reflection_blurb',
        icon: 'chat',
        build: () => [
            heading(t('templates.reflection_heading')),
            list(
                [
                    t('templates.reflection_one'),
                    t('templates.reflection_two'),
                    t('templates.reflection_three'),
                ],
                true,
            ),
        ],
    },
];

export default function TemplatesPanel({ section, onInsert }) {
    useI18n();
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
                    {t('templates.title')}
                </h2>
                <p className="mt-1.5 text-[14px] text-text-secondary">
                    {section
                        ? t('templates.drop_into', { title: section.title })
                        : t('templates.open_chapter')}
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
                                {t(template.tKey)}
                            </p>
                            <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-secondary">
                                {t(template.blurbKey)}
                            </p>
                        </div>

                        <button
                            data-a={`templates:insert:${template.id}`}
                            disabled={!section}
                            onClick={() => insert(template)}
                            title={section ? undefined : t('templates.open_chapter_first')}
                            className="h-[32px] shrink-0 rounded-control bg-brand-fill px-3 text-[12.5px] font-semibold text-white transition hover:bg-brand-primary-hover disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {inserted === template.id ? t('templates.inserted') : t('templates.insert')}
                        </button>
                    </li>
                ))}
            </ul>

            <p className="mt-5 text-[11.5px] leading-relaxed text-text-secondary">
                {t('templates.footnote')}
            </p>
        </div>
    );
}
