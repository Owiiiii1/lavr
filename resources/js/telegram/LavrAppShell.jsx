import LavrBottomNav from '@/telegram/LavrBottomNav';
import TelegramWebAppBridge from '@/telegram/TelegramWebAppBridge';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function LavrAppShell({ children, showBottomNav = true }) {
    const [isTelegram, setIsTelegram] = useState(false);

    useEffect(() => {
        let cancelled = false;

        TelegramWebAppBridge.boot().then((inside) => {
            if (!cancelled) {
                setIsTelegram(inside);
            }
        });

        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        if (!isTelegram) {
            TelegramWebAppBridge.backButton.hide();

            return undefined;
        }

        const path = window.location.pathname;
        const atRoot = path === '/lavr/today' || path === '/lavr';

        if (atRoot) {
            TelegramWebAppBridge.backButton.hide();

            return undefined;
        }

        const onBack = () => {
            if (window.history.length > 1) {
                window.history.back();

                return;
            }

            router.visit('/lavr/today');
        };

        TelegramWebAppBridge.backButton.show(onBack);

        return () => TelegramWebAppBridge.backButton.hide();
    }, [isTelegram]);

    return (
        <div className={`lavr-shell ${isTelegram ? 'lavr-shell--telegram' : ''}`}>
            <div className="lavr-shell__body">{children}</div>
            {showBottomNav ? <LavrBottomNav force={isTelegram} /> : null}
        </div>
    );
}
