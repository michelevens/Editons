export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-border-input text-brand-primary shadow-sm focus:ring-brand-primary ' +
                className
            }
        />
    );
}
