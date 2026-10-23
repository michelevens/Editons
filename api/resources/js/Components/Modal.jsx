import Dialog from './Dialog';

/**
 * Breeze's modal, kept for its callers but drawn by the studio's one
 * accessible Dialog, so every modal in the app traps focus, closes on Escape
 * and gives focus back the same way.
 */
export default function Modal({
    children,
    show = false,
    maxWidth = '2xl',
    closeable = true,
    label = 'Dialog',
    onClose = () => {},
}) {
    if (!show) return null;

    const maxWidthClass = {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[maxWidth];

    return (
        <Dialog
            label={label}
            onClose={() => closeable && onClose()}
            className={`w-full overflow-hidden rounded-panel border border-border-default bg-bg-surface shadow-xl ${maxWidthClass}`}
        >
            {children}
        </Dialog>
    );
}
