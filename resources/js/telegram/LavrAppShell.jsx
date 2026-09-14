import LavrBottomNav from '@/telegram/LavrBottomNav';
import TelegramWebAppBridge from '@/telegram/TelegramWebAppBridge';
import { useTranslation } from '@/locales/useTranslation';
import { router, usePage } from '@inertiajs/react';
import { Component, useEffect, useState } from 'react';

function isRootPath(path) {
    return path === '/lavr/today' || path === '/lavr';
}

class WorkspaceBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { failed: false };
    }

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        if (this.state.failed) {
            return (
                <div className="px-4 py-8 text-sm text-slate-300">
                    {this.props.fallback || 'This section is unavailable right now.'}
                </div>
            );
        }

        return this.props.children;
    }
}

export default function LavrAppShell({ children, showBottomNav = true, fill = false }) {
    const page = usePage();
    const path = String(page.url || '').split('?')[0];
    const { t } = useTranslation();
    const [isTelegram, setIsTelegram] = useState(false);
    const [keyboardOpen, setKeyboardOpen] = useState(false);

    useEffect(() => {
        let cancelled = false;

        TelegramWebAppBridge.boot().then((inside) => {
            if (!cancelled) {
                setIsTelegram(inside);
                setKeyboardOpen(document.documentElement.classList.contains('lavr-keyboard-open'));
            }
        });

        const onViewport = () => {
            setKeyboardOpen(document.documentElement.classList.contains('lavr-keyboard-open'));
        };

        window.addEventListener('lavr-telegram-viewport', onViewport);

        return () => {
            cancelled = true;
            window.removeEventListener('lavr-telegram-viewport', onViewport);
        };
    }, []);

    useEffect(() => {
        if (!isTelegram) {
            TelegramWebAppBridge.backButton.hide();

            return undefined;
        }

        if (isRootPath(path)) {
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

        return () => {
            TelegramWebAppBridge.backButton.offClick(onBack);
            TelegramWebAppBridge.backButton.hide();
        };
    }, [isTelegram, path]);

    const shellClass = [
        'lavr-shell',
        isTelegram ? 'lavr-shell--telegram' : '',
        fill ? 'lavr-shell--fill' : '',
        keyboardOpen ? 'lavr-shell--keyboard' : '',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div className={shellClass}>
            <div className="lavr-shell__body">
                <WorkspaceBoundary fallback={t('today.sectionUnavailable')}>{children}</WorkspaceBoundary>
            </div>
            {showBottomNav ? <LavrBottomNav force={isTelegram} /> : null}
        </div>
    );
}
