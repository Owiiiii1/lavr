import TelegramWebAppBridge from '@/telegram/TelegramWebAppBridge';
import { translate, useTranslation } from '@/locales/useTranslation';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function sleep(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

async function waitForInitData() {
    for (let attempt = 0; attempt < 15; attempt += 1) {
        const initData = TelegramWebAppBridge.initData();

        if (initData.includes('hash=') || initData.includes('signature=')) {
            return initData;
        }

        await sleep(80);
    }

    return TelegramWebAppBridge.initData();
}

export default function WebAppBoot({ startParam = null }) {
    const { locale, t } = useTranslation();
    const [message, setMessage] = useState(() => translate(locale, 'webapp.booting'));

    useEffect(() => {
        let cancelled = false;

        (async () => {
            await TelegramWebAppBridge.boot();

            if (cancelled) {
                return;
            }

            const initData = await waitForInitData();

            if (cancelled) {
                return;
            }

            if (initData === '') {
                setMessage(translate(locale, 'webapp.sdk'));

                return;
            }

            setMessage(translate(locale, 'webapp.authenticating'));

            const params = new URLSearchParams(window.location.search);
            const payload = new FormData();
            payload.append('init_data', initData);

            const nextStart = TelegramWebAppBridge.startParam() || startParam || params.get('startapp') || '';

            if (nextStart) {
                payload.append('start_param', nextStart);
            }

            router.post(route('telegram.webapp.session'), payload, {
                forceFormData: true,
                preserveState: false,
                onError: () => {
                    if (!cancelled) {
                        setMessage(translate(locale, 'webapp.invalid'));
                    }
                },
                onFinish: (visit) => {
                    if (!cancelled && visit?.interrupted) {
                        setMessage(translate(locale, 'webapp.unavailable'));
                    }
                },
            });
        })();

        return () => {
            cancelled = true;
        };
    }, [startParam, locale]);

    return (
        <div className="jarvis-workspace flex min-h-[100dvh] items-center justify-center px-6 text-center">
            <Head title={t('common.lavr')} />
            <div className="max-w-sm space-y-3">
                <p className="text-lg font-semibold text-white">{t('common.lavr')}</p>
                <p className="text-sm leading-6 text-slate-300">{message}</p>
            </div>
        </div>
    );
}
