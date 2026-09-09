import { Head } from '@inertiajs/react';

const COPY = {
    not_linked: 'Этот Telegram-аккаунт не связан с LAVR. Подключите его в настройках LAVR.',
    invalid: 'Не удалось подтвердить вход. Откройте LAVR снова из Telegram.',
    expired: 'Сессия истекла. Откройте LAVR снова из Telegram.',
    unavailable: 'LAVR сейчас недоступен. Попробуйте позже.',
};

export default function WebAppBlocked({ reason = 'not_linked', bot_chat_href = null }) {
    const message = COPY[reason] || COPY.not_linked;

    return (
        <div className="jarvis-workspace flex min-h-[100dvh] items-center justify-center px-6 text-center">
            <Head title="LAVR" />
            <div className="max-w-sm space-y-4">
                <p className="text-lg font-semibold text-white">LAVR</p>
                <p className="text-sm leading-6 text-slate-300">{message}</p>
                {reason === 'not_linked' ? (
                    <p className="text-sm leading-6 text-slate-400">
                        Connect this Telegram account from LAVR settings.
                    </p>
                ) : null}
                {reason === 'not_linked' && bot_chat_href ? (
                    <a
                        href={bot_chat_href}
                        className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[var(--tg-theme-button-color,#0ea5e9)] px-5 text-sm font-semibold text-[var(--tg-theme-button-text-color,#fff)]"
                    >
                        Open Telegram chat
                    </a>
                ) : null}
            </div>
        </div>
    );
}
