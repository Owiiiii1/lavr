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

function luminance(hex) {
    const value = String(hex || '').replace('#', '');

    if (value.length !== 6) {
        return null;
    }

    const r = parseInt(value.slice(0, 2), 16) / 255;
    const g = parseInt(value.slice(2, 4), 16) / 255;
    const b = parseInt(value.slice(4, 6), 16) / 255;

    if ([r, g, b].some((channel) => Number.isNaN(channel))) {
        return null;
    }

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
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

    const scheme = webApp?.colorScheme;
    const bgLuminance = luminance(theme.bg_color);
    const isLight = scheme === 'light' || (scheme !== 'dark' && bgLuminance !== null && bgLuminance > 0.62);

    root.classList.toggle('lavr-tg-light', Boolean(isLight));
    root.classList.toggle('lavr-tg-dark', !isLight);

    const safe = webApp?.safeAreaInset || {};
    const contentSafe = webApp?.contentSafeAreaInset || {};
    root.style.setProperty('--lavr-safe-top', `${Number(contentSafe.top || safe.top || 0)}px`);
    root.style.setProperty('--lavr-safe-bottom', `${Number(contentSafe.bottom || safe.bottom || 0)}px`);

    const height = Number(webApp?.viewportHeight || 0);
    const stable = Number(webApp?.viewportStableHeight || 0);
    const nextHeight = height > 0 ? height : stable;

    if (nextHeight > 0) {
        root.style.setProperty('--lavr-viewport-height', `${nextHeight}px`);
    }

    applyKeyboardInset(webApp);
}

function applyKeyboardInset(webApp) {
    const root = document.documentElement;
    const height = Number(webApp?.viewportHeight || 0);
    const stable = Number(webApp?.viewportStableHeight || 0);
    let inset = 0;

    if (stable > 0 && height > 0) {
        inset = Math.max(0, stable - height);
    }

    if (typeof window !== 'undefined' && window.visualViewport) {
        const visual = window.visualViewport;
        const visualInset = Math.max(0, window.innerHeight - visual.height - visual.offsetTop);
        inset = Math.max(inset, visualInset);
    }

    root.style.setProperty('--lavr-keyboard-inset', `${Math.round(inset)}px`);
    root.classList.toggle('lavr-keyboard-open', inset > 80);
    window.dispatchEvent(new Event('lavr-telegram-viewport'));
}

const TelegramWebAppBridge = {
    async boot() {
        await loadOfficialScript();
        const webApp = telegramApi();

        if (!webApp) {
            if (typeof window !== 'undefined' && window.visualViewport) {
                const onVisual = () => applyKeyboardInset(null);
                window.visualViewport.addEventListener('resize', onVisual);
                window.visualViewport.addEventListener('scroll', onVisual);
                onVisual();
            }

            return false;
        }

        try {
            webApp.ready?.();
            webApp.expand?.();
            webApp.MainButton?.hide?.();
            applyTheme(webApp);
            webApp.onEvent?.('themeChanged', () => applyTheme(webApp));
            webApp.onEvent?.('viewportChanged', () => applyTheme(webApp));

            if (window.visualViewport) {
                const onVisual = () => applyKeyboardInset(webApp);
                window.visualViewport.addEventListener('resize', onVisual);
                window.visualViewport.addEventListener('scroll', onVisual);
            }
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
        offClick(onClick) {
            const button = telegramApi()?.BackButton;

            if (!button || typeof onClick !== 'function') {
                return;
            }

            button.offClick?.(onClick);
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
