import { useTranslation } from '@/locales/useTranslation';
import { Head } from '@inertiajs/react';

export default function WebAppBlocked({ reason = 'not_linked', bot_chat_href = null }) {
    const { t } = useTranslation();
    const copy = {
        not_linked: t('webapp.notLinked'),
        invalid: t('webapp.invalid'),
        expired: t('webapp.expired'),
        unavailable: t('webapp.unavailable'),
    };
    const message = copy[reason] || copy.not_linked;

    return (
        <div className="jarvis-workspace flex min-h-[100dvh] items-center justify-center px-6 text-center">
            <Head title={t('common.lavr')} />
            <div className="max-w-sm space-y-4">
                <p className="text-lg font-semibold text-white">{t('common.lavr')}</p>
                <p className="text-sm leading-6 text-slate-300">{message}</p>
                {reason === 'not_linked' ? (
                    <p className="text-sm leading-6 text-slate-400">
                        {t('webapp.notLinkedHint')}
                    </p>
                ) : null}
                {reason === 'not_linked' && bot_chat_href ? (
                    <a
                        href={bot_chat_href}
                        className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[var(--tg-theme-button-color,#0ea5e9)] px-5 text-sm font-semibold text-[var(--tg-theme-button-text-color,#fff)]"
                    >
                        {t('webapp.openChat')}
                    </a>
                ) : null}
            </div>
        </div>
    );
}
