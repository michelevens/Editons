import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useI18n } from '@/i18n';

export default function DeleteUserForm({ className = '' }) {
    const { t } = useI18n();
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef();

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        clearErrors();
        reset();
    };

    return (
        // No header: the Box around this says what deleting costs, in terms
        // of the manuscripts rather than "resources and data".
        <section className={className}>
            <DangerButton onClick={confirmUserDeletion} data-a="profile:delete">
                {t('profile.delete_my_account')}
            </DangerButton>

            <Modal show={confirmingUserDeletion} onClose={closeModal} label={t('profile.delete_modal_label')}>
                <form onSubmit={deleteUser} className="p-6">
                    <h2 className="text-lg font-medium text-text-primary">
                        {t('profile.delete_confirm_title')}
                    </h2>

                    <p className="mt-1 text-sm text-text-secondary">
                        {t('profile.delete_confirm_body')}
                    </p>

                    <div className="mt-6">
                        <InputLabel
                            htmlFor="password"
                            value={t('signin.password')}
                            className="sr-only"
                        />

                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) =>
                                setData('password', e.target.value)
                            }
                            className="mt-1 block w-3/4"
                            isFocused
                            placeholder={t('signin.password')}
                        />

                        <InputError
                            message={errors.password}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>
                            {t('common.cancel')}
                        </SecondaryButton>

                        <DangerButton className="ms-3" disabled={processing}>
                            {t('profile.delete_account_button')}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
