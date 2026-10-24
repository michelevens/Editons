import { useForm } from '@inertiajs/react';
import { useState } from 'react';

const input =
    'w-full rounded-control border border-border-input bg-bg-surface px-3 py-2 text-[13.5px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/20';
const button = 'tap inline-flex items-center justify-center rounded-button px-3.5 py-2 text-[13px] font-semibold transition disabled:opacity-50';

/**
 * Two-factor sign-in: turn it on (password, scan, confirm with a code),
 * see the recovery codes once, make new ones, turn it off.
 *
 * Shared by the profile and the admin console's set-up page; both post to
 * the same /user/two-factor endpoints.
 */
export default function TwoFactorPanel({ state }) {
    if (state.enabled) return <Enabled state={state} />;
    if (state.pending) return <Pending state={state} />;

    return <Start required={state.required} />;
}

function Start({ required }) {
    const form = useForm({ password: '' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('two-factor.enable'), { preserveScroll: true, onSuccess: () => form.reset() });
            }}
            className="space-y-3"
        >
            <p className="text-[13px] text-text-body">
                Off. With it on, signing in also asks for a six-digit code from an authenticator app (1Password, Google Authenticator, Authy…).
                {required && <strong className="text-status-danger"> Required for Ecrivain staff.</strong>}
            </p>
            <div className="max-w-[320px]">
                <label htmlFor="tf-password" className="text-[12.5px] font-medium text-text-primary">
                    Your password
                </label>
                <input
                    id="tf-password"
                    type="password"
                    autoComplete="current-password"
                    data-a="2fa:password"
                    required
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    className={`${input} mt-1`}
                />
                {form.errors.password && <p role="alert" className="mt-1 text-[12px] text-status-danger">{form.errors.password}</p>}
            </div>
            <button type="submit" data-a="2fa:enable" disabled={form.processing} className={`${button} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                Turn on two-factor
            </button>
        </form>
    );
}

function Pending({ state }) {
    const form = useForm({ code: '' });
    const cancel = useForm({ password: '' });
    const [showCancel, setShowCancel] = useState(false);

    return (
        <div className="space-y-3">
            <p className="text-[13px] text-text-body">Scan this with your authenticator app, then type the six-digit code it shows.</p>
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                {/* Server-drawn SVG of the otpauth address (bacon/bacon-qr-code), always on white so it scans in dark mode. */}
                <div
                    className="w-[200px] shrink-0 rounded-tile bg-white p-1"
                    data-a="2fa:qr"
                    role="img"
                    aria-label="QR code for your authenticator app"
                    dangerouslySetInnerHTML={{ __html: state.qr_svg }}
                />
                <div className="min-w-0 space-y-3">
                    <div>
                        <p className="text-[12px] text-text-secondary">Or enter this key by hand:</p>
                        <code className="break-all text-[13px] font-semibold tracking-[0.08em] text-text-primary" data-a="2fa:secret">
                            {state.secret}
                        </code>
                    </div>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(route('two-factor.confirm'), { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                        className="space-y-2"
                    >
                        <label htmlFor="tf-code" className="text-[12.5px] font-medium text-text-primary">
                            Code from the app
                        </label>
                        <input
                            id="tf-code"
                            data-a="2fa:code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            pattern="[0-9 ]{6,7}"
                            required
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            className={`${input} max-w-[180px] tracking-[0.3em]`}
                        />
                        {form.errors.code && <p role="alert" className="text-[12px] text-status-danger">{form.errors.code}</p>}
                        <div>
                            <button type="submit" data-a="2fa:confirm" disabled={form.processing} className={`${button} bg-brand-fill text-white hover:bg-brand-primary-hover`}>
                                Confirm and turn on
                            </button>
                        </div>
                    </form>
                    {showCancel ? (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                cancel.delete(route('two-factor.disable'), { preserveScroll: true });
                            }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <div>
                                <label htmlFor="tf-cancel" className="text-[12px] text-text-secondary">
                                    Password, to cancel set-up
                                </label>
                                <input
                                    id="tf-cancel"
                                    type="password"
                                    value={cancel.data.password}
                                    onChange={(event) => cancel.setData('password', event.target.value)}
                                    className={`${input} mt-1 max-w-[220px]`}
                                />
                            </div>
                            <button type="submit" className={`${button} border border-border-default text-text-primary`}>
                                Cancel set-up
                            </button>
                        </form>
                    ) : (
                        <button type="button" onClick={() => setShowCancel(true)} className="text-[12px] text-text-secondary underline">
                            Cancel set-up
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

function Enabled({ state }) {
    const [mode, setMode] = useState(null);
    const form = useForm({ password: '', code: '' });

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { form.reset(); setMode(null); } };

        if (mode === 'disable') form.delete(route('two-factor.disable'), options);
        else form.post(route('two-factor.recovery-codes'), options);
    };

    return (
        <div className="space-y-3">
            <p className="text-[13px] text-status-success" data-a="2fa:on">
                On since {new Date(state.confirmed_at).toLocaleDateString()}. {state.recovery_remaining} recovery code
                {state.recovery_remaining === 1 ? '' : 's'} left.
            </p>

            {state.recovery_codes && (
                <div className="rounded-tile border border-status-warning/40 bg-status-warning-bg p-3" data-a="2fa:recovery-codes">
                    <p className="text-[12.5px] font-semibold text-status-warning">
                        Your recovery codes. Save them now — they are shown once. Each signs you in once if you lose your phone.
                    </p>
                    <ul className="mt-2 grid grid-cols-2 gap-1 font-mono text-[13px] text-text-primary sm:grid-cols-4">
                        {state.recovery_codes.map((code) => (
                            <li key={code}>{code}</li>
                        ))}
                    </ul>
                </div>
            )}

            {mode ? (
                <form onSubmit={submit} className="space-y-2">
                    <p className="text-[12.5px] text-text-secondary">
                        {mode === 'disable' ? 'To turn it off' : 'To make new recovery codes (the old ones stop working)'}, enter your password and a current code.
                    </p>
                    <div className="grid max-w-[480px] grid-cols-1 gap-2 sm:grid-cols-2">
                        <input
                            type="password"
                            aria-label="Your password"
                            placeholder="Password"
                            autoComplete="current-password"
                            required
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            className={input}
                        />
                        <input
                            aria-label="Code from the app, or a recovery code"
                            placeholder="Code or recovery code"
                            autoComplete="one-time-code"
                            required
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            className={input}
                        />
                    </div>
                    {Object.values(form.errors).map((message) => (
                        <p key={message} role="alert" className="text-[12px] text-status-danger">
                            {message}
                        </p>
                    ))}
                    <div className="flex gap-2">
                        <button type="submit" disabled={form.processing} className={`${button} ${mode === 'disable' ? 'bg-status-danger-fill' : 'bg-brand-fill'} text-white`}>
                            {mode === 'disable' ? 'Turn off two-factor' : 'Make new codes'}
                        </button>
                        <button type="button" onClick={() => setMode(null)} className={`${button} text-text-secondary`}>
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => setMode('codes')} className={`${button} border border-border-default text-text-primary hover:bg-bg-subtle`}>
                        New recovery codes
                    </button>
                    <button type="button" data-a="2fa:disable" onClick={() => setMode('disable')} className={`${button} border border-status-danger/40 text-status-danger hover:bg-status-danger-bg`}>
                        Turn off
                    </button>
                </div>
            )}
        </div>
    );
}
