import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link } from '@inertiajs/react';

/**
 * What everybody but staff sees while maintenance mode is on (503, from
 * MaintenanceGate). Says why, says the books are safe, and offers the two
 * doors still open: sign in (for staff) and sign out.
 */
export default function Maintenance({ message, signed_in: signedIn }) {
    return (
        <main id="main" className="grid min-h-dvh place-items-center bg-bg-app px-4 font-ui text-text-body" data-a="maintenance:page">
            <Head title="Down for maintenance" />
            <div className="w-full max-w-[460px] rounded-panel border border-border-default bg-bg-surface p-6 text-center sm:p-8">
                <ApplicationLogo className="mx-auto h-9 w-auto" />
                <h1 className="mt-5 font-display text-[24px] font-extrabold tracking-[-0.02em] text-text-primary">Down for maintenance</h1>
                <p className="mt-2 text-[14px] text-text-body" data-a="maintenance:message">
                    {message}
                </p>
                <p className="mt-4 text-[12.5px] text-text-secondary">
                    Nothing you have written is affected. <a href="/status" className="text-brand-primary hover:underline">Check the status</a>.
                </p>
                <div className="mt-6 text-[13px]">
                    {signedIn ? (
                        <Link href={route('logout')} method="post" as="button" className="text-text-secondary underline">
                            Sign out
                        </Link>
                    ) : (
                        <Link href={route('login')} className="text-text-secondary underline">
                            Staff sign-in
                        </Link>
                    )}
                </div>
            </div>
        </main>
    );
}
