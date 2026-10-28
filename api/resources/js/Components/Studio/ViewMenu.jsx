import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from './Icon';
import {
    BUILTIN_PRESETS,
    CANVAS_FONTS,
    CANVAS_WIDTHS,
    LINE_HEIGHTS,
    matchesPreset,
    SHORTCUTS,
    shortcutLabel,
} from './workspace';

/**
 * View: every panel of the studio, the layouts, and the writing canvas, in
 * one place an author can reach whatever is hidden (ADR — customisable
 * writing workspace).
 *
 * In the chapter header rather than the top nav, because the top nav is one
 * of the things it can hide; the chapter header is the one row that is
 * always there while writing. A non-modal popover, so a toggle shows its
 * effect behind it at once; Escape or a click elsewhere closes it and focus
 * goes back to the button.
 *
 * Every switch is a button with aria-pressed and its shortcut, if it has
 * one. On a phone it is a bottom sheet with only what a phone has: the
 * canvas, the toolbar and the status bar.
 */

export const PANEL_LABELS = {
    topNav: 'Top navigation',
    sidebar: 'Book sidebar',
    credits: 'Credit balance',
    outline: 'Chapter outline',
    right: 'AI editor panel',
    bottom: 'Bottom cards',
    toolbar: 'Formatting toolbar',
    statusBar: 'Status bar',
};

const SUBPANELS = [
    { group: 'Chapter outline', items: [
        { path: ['outlineTabs'], label: 'Tabs (Manuscript, Outline, Notes)' },
        { path: ['outlineTools'], label: 'Search and chapter actions' },
        { path: ['outlineGoals'], label: 'Word-goal rings' },
    ] },
    { group: 'AI editor panel', items: [
        { path: ['rightTabs', 'editor'], label: 'AI Editor tab' },
        { path: ['rightTabs', 'comments'], label: 'Comments tab' },
        { path: ['rightTabs', 'research'], label: 'Research tab' },
        { path: ['rightTabs', 'checks'], label: 'Checks tab' },
    ] },
    { group: 'Bottom cards', items: [
        { path: ['bottomCards', 'research'], label: 'Research library' },
        { path: ['bottomCards', 'design'], label: 'Book design' },
        { path: ['bottomCards', 'cover'], label: 'Cover studio' },
        { path: ['bottomCards', 'export'], label: 'Export & publish' },
    ] },
];

function nested(path, value) {
    return path.reduceRight((inner, key) => ({ [key]: inner }), value);
}

function read(object, path) {
    return path.reduce((value, key) => value?.[key], object);
}

export default function ViewMenu({ ws, focused, onToggleFocus, onTogglePanel, onToggleRail, onApplyPreset, bookId }) {
    const [open, setOpen] = useState(false);
    const [phone, setPhone] = useState(false);
    const [position, setPosition] = useState(null);
    const [naming, setNaming] = useState(false);
    const [name, setName] = useState('');
    const [problem, setProblem] = useState(null);
    const trigger = useRef(null);
    const panel = useRef(null);
    const id = useId();

    const { settings } = ws;

    useEffect(() => {
        const narrow = window.matchMedia('(max-width: 767px)');
        const update = () => setPhone(narrow.matches);

        update();
        narrow.addEventListener('change', update);

        return () => narrow.removeEventListener('change', update);
    }, []);

    // Under its button, right-aligned, inside the window.
    useLayoutEffect(() => {
        if (!open || !trigger.current) return undefined;

        const place = () => {
            const box = trigger.current.getBoundingClientRect();
            const width = Math.min(360, window.innerWidth - 16);

            setPosition({
                top: box.bottom + 6,
                left: Math.max(8, Math.min(box.right - width, window.innerWidth - width - 8)),
                width,
                maxHeight: window.innerHeight - box.bottom - 18,
            });
        };

        place();
        window.addEventListener('resize', place);

        return () => window.removeEventListener('resize', place);
    }, [open]);

    // Focus in on open; Escape or a click outside closes it.
    useEffect(() => {
        if (!open) return undefined;

        window.setTimeout(() => panel.current?.querySelector('button, input, select')?.focus(), 0);

        const onKey = (event) => {
            if (event.key === 'Escape') {
                // Before the studio's own Escape (leave distraction-free).
                event.stopPropagation();
                close();
            }
        };
        const onDown = (event) => {
            if (panel.current?.contains(event.target) || trigger.current?.contains(event.target)) return;

            setOpen(false);
        };

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onDown);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('pointerdown', onDown);
        };
    }, [open]);

    const close = () => {
        setOpen(false);
        setNaming(false);
        trigger.current?.focus();
    };

    const canvas = (changes) => ws.update({ canvas: changes });
    const activePreset = settings.preset;

    const savePreset = async (event) => {
        event.preventDefault();

        if (!name.trim()) return;

        try {
            await ws.savePreset(name);
            setNaming(false);
            setName('');
            setProblem(null);
        } catch (failure) {
            setProblem(failure.response?.data?.errors?.name?.[0] ?? failure.response?.data?.message ?? 'That layout could not be saved.');
        }
    };

    const toggle = (key) => (
        <Toggle
            key={key}
            label={PANEL_LABELS[key]}
            pressed={settings.panels[key]}
            shortcut={{ outline: 'outline', right: 'right', bottom: 'bottom' }[key]}
            onClick={() => onTogglePanel(key)}
            data-a={`view:panel:${key}`}
            desktop={!['toolbar', 'statusBar'].includes(key)}
        />
    );

    const body = (
        <div
            ref={panel}
            id={id}
            role="dialog"
            aria-modal="false"
            aria-label="View"
            data-a="view:menu"
            style={phone ? undefined : position ?? { visibility: 'hidden' }}
            className={`fixed z-[65] flex flex-col overflow-hidden border border-border-default bg-bg-surface shadow-popover ${
                phone
                    ? 'inset-x-0 bottom-0 max-h-[82dvh] rounded-t-panel pb-[env(safe-area-inset-bottom)]'
                    : 'rounded-panel'
            }`}
        >
            <div className="flex shrink-0 items-center justify-between border-b border-border-divider px-4 py-2.5">
                <h2 className="text-[13px] font-semibold text-text-primary">View</h2>
                <button
                    type="button"
                    onClick={close}
                    data-a="view:close"
                    className="tap grid h-7 w-7 place-items-center rounded-control text-text-secondary hover:bg-bg-subtle hover:text-text-primary"
                >
                    <Icon name="x" size={15} />
                    <span className="sr-only">Close View</span>
                </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4 text-[12.5px]">
                {/* Layouts: a phone has none of the panels they arrange. */}
                <Section title="Layouts" desktop>
                    <div className="grid grid-cols-3 gap-1.5">
                        {BUILTIN_PRESETS.map((preset) => (
                            <button
                                key={preset.id}
                                type="button"
                                data-a={`view:preset:${preset.id}`}
                                aria-pressed={activePreset === preset.name && matchesPreset(settings, preset.layout)}
                                title={preset.hint}
                                onClick={() => onApplyPreset(preset)}
                                className={`rounded-control border px-2 py-1.5 text-[12px] font-semibold transition ${
                                    activePreset === preset.name
                                        ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary'
                                        : 'border-border-input text-text-body hover:border-brand-primary/50'
                                }`}
                            >
                                {preset.name}
                            </button>
                        ))}
                    </div>

                    {ws.presets.length > 0 && (
                        <ul className="mt-2 space-y-1" aria-label="Your layouts">
                            {ws.presets.map((preset) => (
                                <li key={preset.id} className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        data-a={`view:mine:${preset.name}`}
                                        aria-pressed={activePreset === preset.name}
                                        onClick={() => onApplyPreset({ ...preset, custom: true })}
                                        className={`min-w-0 flex-1 truncate rounded-control px-2 py-1.5 text-left font-medium ${
                                            activePreset === preset.name ? 'bg-brand-primary-subtle text-brand-primary' : 'text-text-body hover:bg-bg-subtle'
                                        }`}
                                    >
                                        {preset.name}
                                    </button>
                                    <button
                                        type="button"
                                        data-a={`view:mine:delete:${preset.name}`}
                                        onClick={() => ws.deletePreset(preset.id)}
                                        className="grid h-7 w-7 shrink-0 place-items-center rounded-control text-text-secondary hover:bg-bg-subtle hover:text-status-danger"
                                    >
                                        <Icon name="x" size={13} />
                                        <span className="sr-only">Delete layout {preset.name}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {naming ? (
                        <form onSubmit={savePreset} className="mt-2 flex gap-1.5">
                            <label className="sr-only" htmlFor={`${id}-name`}>Layout name</label>
                            <input
                                id={`${id}-name`}
                                data-a="view:preset-name"
                                autoFocus
                                maxLength={40}
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                placeholder="Name this layout"
                                className="h-8 min-w-0 flex-1 rounded-control border border-border-input bg-bg-surface px-2 text-[12.5px] text-text-body focus:border-brand-primary focus:ring-0"
                            />
                            <button type="submit" data-a="view:preset-save" className="rounded-control bg-brand-fill px-3 text-[12px] font-semibold text-white hover:bg-brand-primary-hover">
                                Save
                            </button>
                        </form>
                    ) : (
                        <button
                            type="button"
                            data-a="view:save-as"
                            onClick={() => setNaming(true)}
                            className="mt-2 text-[12px] font-semibold text-brand-primary hover:underline"
                        >
                            Save current layout as…
                        </button>
                    )}
                    {problem && <p role="alert" className="mt-1 text-[11.5px] text-status-danger">{problem}</p>}
                </Section>

                <Section title="Panels">
                    <Toggle
                        label="Distraction-free"
                        pressed={focused}
                        shortcut="focus"
                        onClick={onToggleFocus}
                        data-a="view:focus"
                    />
                    {['topNav', 'sidebar', 'credits', 'outline', 'right', 'bottom', 'toolbar', 'statusBar'].map(toggle)}
                    <Toggle
                        label="Outline as icons"
                        pressed={settings.rails.outline}
                        onClick={() => onToggleRail('outline')}
                        data-a="view:rail:outline"
                        desktop
                    />
                    <Toggle
                        label="AI panel as icons"
                        pressed={settings.rails.right}
                        onClick={() => onToggleRail('right')}
                        data-a="view:rail:right"
                        desktop
                    />
                    <Toggle
                        label="Swap side panels"
                        pressed={settings.swap}
                        onClick={() => ws.update({ swap: !settings.swap })}
                        data-a="view:swap"
                        desktop
                    />
                </Section>

                <Section title="Inside the panels" desktop>
                    {SUBPANELS.map((group) => (
                        <fieldset key={group.group} className="mb-1.5">
                            <legend className="py-1 text-[11px] font-medium text-text-secondary">{group.group}</legend>
                            {group.items.map((item) => {
                                const on = read(settings.subpanels, item.path);
                                // The last tab of the AI panel stays: hiding
                                // every tab is hiding the panel, which has
                                // its own switch.
                                const last = item.path[0] === 'rightTabs' && on
                                    && Object.values(settings.subpanels.rightTabs).filter(Boolean).length === 1;

                                return (
                                    <Toggle
                                        key={item.path.join('.')}
                                        label={item.label}
                                        pressed={on}
                                        disabled={last}
                                        onClick={() => ws.update({ subpanels: nested(item.path, !on) })}
                                        data-a={`view:sub:${item.path.join('.')}`}
                                    />
                                );
                            })}
                        </fieldset>
                    ))}
                </Section>

                <Section title="Writing canvas">
                    <p className="mb-1 text-[11px] text-text-secondary">
                        How the chapter reads while you write. The book&apos;s design and its exports are set in Design.
                    </p>

                    <Field label="Text width">
                        <div role="group" aria-label="Text width" className="grid grid-cols-5 gap-1">
                            {CANVAS_WIDTHS.map((width) => (
                                <button
                                    key={width.key}
                                    type="button"
                                    data-a={`view:width:${width.key}`}
                                    aria-pressed={settings.canvas.width === width.key}
                                    onClick={() => canvas({ width: width.key })}
                                    className={segment(settings.canvas.width === width.key)}
                                >
                                    {width.label}
                                </button>
                            ))}
                        </div>
                        {settings.canvas.width === 'custom' && (
                            <label className="mt-1.5 flex items-center gap-2 text-text-secondary">
                                Width
                                <input
                                    type="number"
                                    data-a="view:custom-width"
                                    min={400}
                                    max={1600}
                                    step={20}
                                    defaultValue={settings.canvas.customWidth}
                                    onBlur={(event) => commitNumber(event, 400, 1600, (n) => canvas({ customWidth: n }))}
                                    onKeyDown={(event) => event.key === 'Enter' && event.currentTarget.blur()}
                                    className="h-8 w-24 rounded-control border border-border-input bg-bg-surface px-2 text-[12.5px] text-text-body focus:border-brand-primary focus:ring-0"
                                />
                                px
                            </label>
                        )}
                    </Field>

                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Font" htmlFor={`${id}-font`}>
                            <select
                                id={`${id}-font`}
                                data-a="view:font"
                                value={settings.canvas.font}
                                onChange={(event) => canvas({ font: event.target.value })}
                                className={select}
                            >
                                {CANVAS_FONTS.map((font) => <option key={font.key} value={font.key}>{font.label}</option>)}
                            </select>
                        </Field>

                        <Field label="Size" htmlFor={`${id}-size`}>
                            <select
                                id={`${id}-size`}
                                data-a="view:size"
                                value={settings.canvas.fontSize}
                                onChange={(event) => canvas({ fontSize: Number(event.target.value) })}
                                className={select}
                            >
                                {Array.from({ length: 16 }, (_, i) => 13 + i).map((size) => (
                                    <option key={size} value={size}>{size} px</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Line spacing" htmlFor={`${id}-leading`}>
                            <select
                                id={`${id}-leading`}
                                data-a="view:leading"
                                value={settings.canvas.lineHeight}
                                onChange={(event) => canvas({ lineHeight: Number(event.target.value) })}
                                className={select}
                            >
                                {[...new Set([...LINE_HEIGHTS, settings.canvas.lineHeight])].sort().map((value) => (
                                    <option key={value} value={value}>{value === 1.41 ? '1.4 (default)' : value}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Toolbar">
                            <div role="group" aria-label="Toolbar" className="grid grid-cols-2 gap-1">
                                {[['docked', 'Docked'], ['floating', 'On selection']].map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        data-a={`view:toolbar:${key}`}
                                        aria-pressed={settings.canvas.toolbarStyle === key}
                                        onClick={() => canvas({ toolbarStyle: key })}
                                        className={segment(settings.canvas.toolbarStyle === key)}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </Field>
                    </div>

                    <Field label="Background">
                        <div role="group" aria-label="Background" className="grid grid-cols-2 gap-1">
                            {[['plain', 'Plain'], ['paper', 'Paper']].map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    data-a={`view:background:${key}`}
                                    aria-pressed={settings.canvas.background === key}
                                    onClick={() => canvas({ background: key })}
                                    className={segment(settings.canvas.background === key)}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </Field>

                    <Toggle label="Page view" pressed={settings.canvas.pageView} onClick={() => canvas({ pageView: !settings.canvas.pageView })} data-a="view:page" />
                    <Toggle label="Typewriter scrolling" pressed={settings.canvas.typewriter} onClick={() => canvas({ typewriter: !settings.canvas.typewriter })} data-a="view:typewriter" />
                    <p className="mt-1 text-[11px] text-text-secondary">
                        Light or dark follows the app theme, in your account menu.
                    </p>
                </Section>

                <Section title="Saved for">
                    {bookId && (
                        <label className="flex items-center gap-2 py-1 text-text-body">
                            <input
                                type="checkbox"
                                data-a="view:book-only"
                                checked={ws.scope === 'book'}
                                onChange={(event) => ws.setScope(event.target.checked ? 'book' : 'user')}
                                className="rounded border-border-input text-brand-fill focus:ring-0"
                            />
                            Use this layout for this book only
                        </label>
                    )}
                    <button
                        type="button"
                        data-a="view:reset"
                        onClick={() => ws.reset()}
                        className="mt-1 flex items-center gap-1.5 text-[12px] font-semibold text-brand-primary hover:underline"
                    >
                        <Icon name="reset" size={13} /> Reset to default
                    </button>
                    {ws.error && <p role="alert" className="mt-1 text-[11.5px] text-status-danger">{ws.error}</p>}
                </Section>
            </div>
        </div>
    );

    return (
        <>
            <button
                ref={trigger}
                type="button"
                data-a="view:open"
                aria-expanded={open}
                aria-controls={open ? id : undefined}
                aria-haspopup="dialog"
                onClick={() => (open ? close() : setOpen(true))}
                title="View: panels, layouts and the writing canvas"
                className={`grid h-7 w-7 shrink-0 place-items-center rounded-control transition max-md:h-9 max-md:w-9 ${
                    open ? 'bg-brand-primary-subtle text-brand-primary' : 'text-text-secondary hover:bg-bg-subtle hover:text-text-primary'
                }`}
            >
                <Icon name="layout" size={15} />
                <span className="sr-only">View</span>
            </button>
            {open && createPortal(
                <>
                    {phone && <div aria-hidden="true" className="fixed inset-0 z-[64] bg-black/30" onClick={close} />}
                    {body}
                </>,
                document.body,
            )}
        </>
    );
}

const select = 'h-8 w-full rounded-control border border-border-input bg-bg-surface py-0 pl-2 pr-7 text-[12.5px] text-text-body focus:border-brand-primary focus:ring-0';

function segment(on) {
    return `rounded-control border px-1 py-1.5 text-[11.5px] font-semibold transition ${
        on ? 'border-brand-primary bg-brand-primary-subtle text-brand-primary' : 'border-border-input text-text-body hover:border-brand-primary/50'
    }`;
}

function commitNumber(event, min, max, apply) {
    const n = Math.round(Number(event.currentTarget.value));

    if (!Number.isFinite(n)) return;

    const clamped = Math.min(max, Math.max(min, n));
    event.currentTarget.value = clamped;
    apply(clamped);
}

function Section({ title, desktop = false, children }) {
    return (
        <section className={`border-b border-border-divider py-3 last:border-0 ${desktop ? 'max-md:hidden' : ''}`}>
            <h3 className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[1.2px] text-text-secondary">{title}</h3>
            {children}
        </section>
    );
}

function Field({ label, htmlFor, children }) {
    return (
        <div className="mt-2">
            {htmlFor ? (
                <label htmlFor={htmlFor} className="mb-1 block text-[11px] font-medium text-text-secondary">{label}</label>
            ) : (
                <p className="mb-1 text-[11px] font-medium text-text-secondary">{label}</p>
            )}
            {children}
        </div>
    );
}

/** A switch: a labelled button with aria-pressed, and its shortcut. */
function Toggle({ label, pressed, onClick, shortcut = null, disabled = false, desktop = false, 'data-a': dataA }) {
    return (
        <button
            type="button"
            data-a={dataA}
            aria-pressed={Boolean(pressed)}
            aria-keyshortcuts={shortcut ? SHORTCUTS[shortcut].keys : undefined}
            disabled={disabled}
            title={disabled ? 'The panel keeps at least one tab' : undefined}
            onClick={onClick}
            className={`flex min-h-[32px] w-full items-center gap-2.5 rounded-control px-1.5 text-left text-text-body transition hover:bg-bg-subtle disabled:opacity-50 ${desktop ? 'max-md:hidden' : ''}`}
        >
            <span
                aria-hidden="true"
                className={`relative h-[16px] w-[28px] shrink-0 rounded-full transition ${pressed ? 'bg-brand-fill' : 'bg-border-input'}`}
            >
                <span className={`absolute top-[2px] h-[12px] w-[12px] rounded-full bg-white transition-all ${pressed ? 'left-[14px]' : 'left-[2px]'}`} />
            </span>
            <span className="min-w-0 flex-1">{label}</span>
            {shortcut && (
                <kbd className="shrink-0 font-sans text-[11px] text-text-secondary">{shortcutLabel(shortcut)}</kbd>
            )}
        </button>
    );
}
