import { Head } from '@inertiajs/react';

const COPY = {
    not_linked: 'Telegram account is not linked to this LAVR instance.',
    invalid: 'Авторизация Telegram недействительна.',
    expired: 'Сессия Telegram истекла. Откройте LAVR снова.',
    unavailable: 'LAVR сейчас недоступен.',
};

export default function WebAppBlocked({ reason = 'not_linked' }) {
    return (
        <div className="jarvis-workspace flex min-h-[100dvh] items-center justify-center px-6 text-center">
            <Head title="LAVR" />
            <div className="max-w-sm space-y-3">
                <p className="text-lg font-semibold text-white">LAVR</p>
                <p className="text-sm text-slate-300">{COPY[reason] || COPY.not_linked}</p>
            </div>
        </div>
    );
}
