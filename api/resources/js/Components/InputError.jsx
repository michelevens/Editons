export default function InputError({ message, className = '', ...props }) {
    return message ? (
        <p
            {...props}
            className={'text-sm text-status-danger ' + className}
        >
            {message}
        </p>
    ) : null;
}
