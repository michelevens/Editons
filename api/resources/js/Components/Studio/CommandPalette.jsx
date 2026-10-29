import { useEffect, useMemo, useRef, useState } from 'react';
import Dialog from '../Dialog';
import Icon from './Icon';
import { useI18n } from '../../i18n';

/**
 * Search: jump to a screen or a chapter by typing its name.
 *
 * The header has shown "Search... ⌘K" since the first layout, and on every
 * page it did nothing, or said it was not built. This is the useful core of a
 * command palette: every place the studio can take you, filtered as you
 * type, opened with Enter.
 *
 * `commands` are { key, label, group, icon, run, shortcut? }.
 */
export default function CommandPalette({ open, onClose, commands }) {
    const [query, setQuery] = useState('');
    const { t } = useI18n();
    const [index, setIndex] = useState(0);
    const input = useRef(null);

    useEffect(() => {
        if (open) {
            setQuery('');
            setIndex(0);
            window.setTimeout(() => input.current?.focus(), 0);
        }
    }, [open]);

    const matches = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (!q) return commands;

        return commands.filter((c) =>
            `${c.label} ${c.group}`.toLowerCase().includes(q),
        );
    }, [commands, query]);

    useEffect(() => setIndex(0), [query]);

    if (!open) return null;

    const choose = (command) => {
        onClose();
        command?.run();
    };

    const onKeyDown = (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setIndex((i) => Math.min(i + 1, matches.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setIndex((i) => Math.max(i - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(matches[index]);
        }
    };

    return (
        <Dialog
            label={t('palette.search')}
            onClose={onClose}
            align="top"
            className="w-full max-w-[560px] overflow-hidden rounded-panel border border-border-default bg-bg-surface shadow-xl"
        >
            <div className="flex items-center gap-2.5 border-b border-border-default px-4">
                <Icon name="search" size={17} className="text-text-secondary" />
                <input
                    ref={input}
                    data-autofocus
                    aria-label={t('palette.placeholder_label')}
                    aria-controls="palette-results"
                    data-a="palette:input"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={onKeyDown}
                    placeholder={t('palette.placeholder')}
                    className="h-[48px] flex-1 border-0 bg-transparent px-0 text-[14.5px] text-text-primary placeholder:text-text-secondary focus:ring-0"
                />
                <kbd className="font-sans text-[11px] text-text-secondary">Esc</kbd>
            </div>

            <ul className="max-h-[52vh] overflow-y-auto py-1.5" id="palette-results" aria-label="Results">
                {matches.length === 0 && (
                    <li className="px-4 py-6 text-center text-[13px] text-text-secondary">
                        {t('palette.nothing', { query })}
                    </li>
                )}

                {matches.map((command, i) => (
                    <li key={command.key}>
                        <button
                            data-a={`palette:${command.key}`}
                            aria-current={i === index ? 'true' : undefined}
                            onMouseEnter={() => setIndex(i)}
                            onClick={() => choose(command)}
                            className={`flex h-[38px] w-full items-center gap-3 px-4 text-left text-[13.5px] ${
                                i === index ? 'bg-bg-subtle text-text-primary' : 'text-text-body'
                            }`}
                        >
                            <Icon name={command.icon ?? 'doc'} size={16} className="shrink-0 opacity-70" />
                            <span className="flex-1 truncate">{command.label}</span>
                            {command.shortcut && (
                                <kbd className="shrink-0 font-sans text-[11px] text-text-secondary">{command.shortcut}</kbd>
                            )}
                            <span className="shrink-0 text-[11.5px] text-text-secondary">
                                {command.group}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </Dialog>
    );
}
