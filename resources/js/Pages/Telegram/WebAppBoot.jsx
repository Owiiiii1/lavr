import TelegramWebAppBridge from '@/telegram/TelegramWebAppBridge';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COPY = {
    booting: 'Открываем LAVR…',
    authenticating: 'Проверяем вход…',
    sdk: 'Откройте LAVR из Telegram.',
    invalid: 'Не удалось подтвердить вход. Откройте LAVR снова из Telegram.',
    unavailable: 'LAVR сейчас недоступен. Попробуйте позже.',
};

export default function WebAppBoot({ startParam = null }) {
    const [message, setMessage] = useState(COPY.booting);

    useEffect(() => {
        let cancelled = false;

        TelegramWebAppBridge.boot().then((inside) => {
            if (cancelled) {
                return;
            }

            if (!inside) {
                setMessage(COPY.sdk);

                return;
            }

            setMessage(COPY.authenticating);

            const params = new URLSearchParams(window.location.search);

            router.post(route('telegram.webapp.session'), {
                init_data: TelegramWebAppBridge.initData(),
                start_param: TelegramWebAppBridge.startParam() || startParam || params.get('startapp') || '',
            }, {
                onError: () => {
                    if (!cancelled) {
                        setMessage(COPY.invalid);
                    }
                },
                onFinish: (visit) => {
                    if (!cancelled && visit?.interrupted) {
                        setMessage(COPY.unavailable);
                    }
                },
            });
        });

        return () => {
            cancelled = true;
        };
    }, [startParam]);

    return (
        <div className="jarvis-workspace flex min-h-[100dvh] items-center justify-center px-6 text-center">
            <Head title="LAVR" />
            <div className="max-w-sm space-y-3">
                <p className="text-lg font-semibold text-white">LAVR</p>
                <p className="text-sm leading-6 text-slate-300">{message}</p>
            </div>
        </div>
    );
}
