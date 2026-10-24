import TwoFactorPanel from '@/Components/Platform/TwoFactorPanel';
import AdminLayout, { Panel } from '@/Layouts/AdminLayout';
import { Link } from '@inertiajs/react';

/**
 * Staff without two-factor land here and nowhere else in the console
 * (RequirePlatformTwoFactor). Once it is on, the console opens.
 */
export default function TwoFactor({ two_factor: state }) {
    return (
        <AdminLayout title="Two-factor sign-in" active={null} setupOnly={!state.enabled}>
            <Panel
                title={state.enabled ? 'Two-factor sign-in is on' : 'Turn on two-factor sign-in to use the console'}
                detail="The console can see and change every account. A password alone is not enough to guard it."
            >
                <TwoFactorPanel state={state} />
                {state.enabled && (
                    <Link href={route('admin.dashboard')} data-a="admin:2fa:continue" className="mt-4 inline-block text-[13px] font-semibold text-brand-primary hover:underline">
                        Continue to the console →
                    </Link>
                )}
            </Panel>
        </AdminLayout>
    );
}
