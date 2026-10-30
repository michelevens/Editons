import { useState } from 'react';
import { useI18n } from '@/i18n';
import Icon from './Icon';

/**
 * Coaching for the chapter being written.
 *
 * Coach::forSection() was written and tested and had no caller: the
 * book-level notes reached the guided screen while the per-chapter ones —
 * the only kind useful *while writing* — reached nobody.
 *
 * It sits under the manuscript rather than in the AI panel because that
 * panel is where an author goes deliberately, and this is something they
 * should meet without going anywhere. It is also why it is one line unless
 * opened: a chapter-level note that occupies a third of the writing surface
 * has made the writing harder.
 *
 * Silent when there is nothing true to say, which is most of the time. A
 * coach that always has something becomes something to dismiss.
 */
export default function CoachStrip({ notes = [], onDismiss }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);

    if (notes.length === 0) return null;

    return (
        <div
            data-a="coach:strip"
            className="shrink-0 border-t border-border-divider bg-bg-subtle/60 px-4 py-2"
        >
            <div className="flex items-center gap-2.5">
                <Icon name="bolt" size={14} className="shrink-0 text-brand-primary" />

                <button
                    data-a="coach:toggle"
                    onClick={() => setOpen((value) => !value)}
                    aria-expanded={open}
                    className="min-w-0 flex-1 text-left text-[12px] text-text-secondary transition hover:text-text-primary"
                >
                    <span className="font-medium text-text-primary">{notes[0].note}</span>
                    {notes.length > 1 && (
                        <span className="ml-1.5">
                            {t('coach.and_more', { count: notes.length - 1 })}
                        </span>
                    )}
                </button>

                <button
                    data-a="coach:dismiss"
                    onClick={onDismiss}
                    aria-label={t('common.dismiss')}
                    className="shrink-0 text-text-secondary transition hover:text-text-primary"
                >
                    <Icon name="x" size={14} />
                </button>
            </div>

            {open && (
                <ul className="mt-2 space-y-2 pl-6">
                    {notes.map((note) => (
                        <li key={note.key} data-a={`coach:note:${note.key}`}>
                            <p className="text-[12px] font-medium text-text-primary">
                                {note.note}
                            </p>
                            <p className="mt-0.5 text-[11.5px] leading-relaxed text-text-secondary">
                                {note.detail}
                            </p>
                        </li>
                    ))}

                    <li className="pt-0.5 text-[10.5px] leading-relaxed text-text-secondary">
                        {t('coach.footnote')}
                    </li>
                </ul>
            )}
        </div>
    );
}
