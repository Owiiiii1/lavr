const SCRIPT_SRC = 'https://telegram.org/js/telegram-web-app.js';

function telegramApi() {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.Telegram?.WebApp ?? null;
}

function loadOfficialScript() {
    if (typeof document === 'undefined') {
        return Promise.resolve();
    }

    if (telegramApi() || document.querySelector(`script[src="${SCRIPT_SRC}"]`)) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        const script = document.createElement('script');
        script.src = SCRIPT_SRC;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => resolve();
        document.head.appendChild(script);
    });
}

function applyTheme(webApp) {
    const theme = webApp?.themeParams || {};
    const root = document.documentElement;

    const map = {
        '--tg-theme-bg-color': theme.bg_color,
        '--tg-theme-text-color': theme.text_color,
        '--tg-theme-hint-color': theme.hint_color,
        '--tg-theme-link-color': theme.link_color,
        '--tg-theme-button-color': theme.button_color,
        '--tg-theme-button-text-color': theme.button_text_color,
        '--tg-theme-secondary-bg-color': theme.secondary_bg_color,
    };

    Object.entries(map).forEach(([key, value]) => {
        if (value) {
            root.style.setProperty(key, value);
        }
    });

    const safe = webApp?.safeAreaInset || {};
    const contentSafe = webApp?.contentSafeAreaInset || {};
    root.style.setProperty('--lavr-safe-top', `${Number(contentSafe.top || safe.top || 0)}px`);
    root.style.setProperty('--lavr-safe-bottom', `${Number(contentSafe.bottom || safe.bottom || 0)}px`);

    if (webApp?.viewportStableHeight) {
        root.style.setProperty('--lavr-viewport-height', `${webApp.viewportStableHeight}px`);
    }
}

const TelegramWebAppBridge = {
    async boot() {
        await loadOfficialScript();
        const webApp = telegramApi();

        if (!webApp) {
            return false;
        }

        try {
            webApp.ready?.();
            webApp.expand?.();
            webApp.MainButton?.hide?.();
            applyTheme(webApp);
            webApp.onEvent?.('themeChanged', () => applyTheme(webApp));
            webApp.onEvent?.('viewportChanged', () => applyTheme(webApp));
        } catch {
            return this.isTelegramWebApp();
        }

        return this.isTelegramWebApp();
    },

    isTelegramWebApp() {
        const webApp = telegramApi();

        return Boolean(webApp && typeof webApp.initData === 'string' && webApp.initData.length > 0);
    },

    initData() {
        return telegramApi()?.initData ?? '';
    },

    startParam() {
        const webApp = telegramApi();

        return webApp?.initDataUnsafe?.start_param || '';
    },

    themeParams() {
        return telegramApi()?.themeParams ?? {};
    },

    viewport() {
        const webApp = telegramApi();

        return {
            height: webApp?.viewportHeight ?? null,
            stableHeight: webApp?.viewportStableHeight ?? null,
            isExpanded: Boolean(webApp?.isExpanded),
        };
    },

    safeArea() {
        const webApp = telegramApi();

        return {
            top: Number(webApp?.contentSafeAreaInset?.top || webApp?.safeAreaInset?.top || 0),
            bottom: Number(webApp?.contentSafeAreaInset?.bottom || webApp?.safeAreaInset?.bottom || 0),
            left: Number(webApp?.safeAreaInset?.left || 0),
            right: Number(webApp?.safeAreaInset?.right || 0),
        };
    },

    ready() {
        telegramApi()?.ready?.();
    },

    expand() {
        telegramApi()?.expand?.();
    },

    close() {
        telegramApi()?.close?.();
    },

    openLink(url) {
        const webApp = telegramApi();

        if (webApp?.openLink) {
            webApp.openLink(url);

            return;
        }

        window.open(url, '_blank', 'noopener');
    },

    backButton: {
        show(onClick) {
            const button = telegramApi()?.BackButton;

            if (!button) {
                return;
            }

            if (typeof onClick === 'function') {
                button.onClick(onClick);
            }

            button.show();
        },
        hide() {
            const button = telegramApi()?.BackButton;

            if (!button) {
                return;
            }

            button.hide();
        },
    },

    mainButton: {
        show() {
            telegramApi()?.MainButton?.show?.();
        },
        hide() {
            telegramApi()?.MainButton?.hide?.();
        },
        setText(text) {
            telegramApi()?.MainButton?.setText?.(text);
        },
        onClick(handler) {
            telegramApi()?.MainButton?.onClick?.(handler);
        },
    },
};

export default TelegramWebAppBridge;
