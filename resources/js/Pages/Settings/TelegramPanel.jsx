import { router, usePage } from '@inertiajs/react';
import { Loader2, PlugZap, Power, Save, Webhook } from 'lucide-react';
import { useState } from 'react';

export default function TelegramPanel() {
    const { telegram = {}, locale = 'en', errors = {} } = usePage().props;
    const [token, setToken] = useState('');
    const [busyAction, setBusyAction] = useState(null);

    const text = {
        en: {
            subtitle: 'Connect the Telegram bot token and webhook. This is the only place to manage the bot.',
            token: 'Bot token',
            saveToken: 'Save token',
            check: 'Check bot',
            setWebhook: 'Set webhook',
            removeWebhook: 'Remove webhook',
            username: 'Bot username',
            webhookUrl: 'Webhook URL',
            webhookSecret: 'Webhook secret',
            savedMask: 'Saved token',
            secretConfigured: 'Configured',
            secretMissing: 'Not set',
            status: 'Status',
            notConfigured: 'Not configured',
            tokenSaved: 'Token saved',
            connected: 'Connected',
            webhookSet: 'Webhook set',
            error: 'Error',
        },
        ru: {
            subtitle: 'Токен бота и webhook настраиваются только здесь.',
            token: 'Токен бота',
            saveToken: 'Сохранить токен',
            check: 'Проверить бота',
            setWebhook: 'Задать webhook',
            removeWebhook: 'Убрать webhook',
            username: 'Имя бота',
            webhookUrl: 'URL webhook',
            webhookSecret: 'Секрет webhook',
            savedMask: 'Сохранённый токен',
            secretConfigured: 'Настроено',
            secretMissing: 'Не задан',
            status: 'Статус',
            notConfigured: 'Не настроено',
            tokenSaved: 'Токен сохранён',
            connected: 'Подключено',
            webhookSet: 'Webhook задан',
            error: 'Ошибка',
        },
        uk: {
            subtitle: 'Токен бота і webhook налаштовуються лише тут.',
            token: 'Токен бота',
            saveToken: 'Зберегти токен',
            check: 'Перевірити бота',
            setWebhook: 'Задати webhook',
            removeWebhook: 'Прибрати webhook',
            username: 'Ім’я бота',
            webhookUrl: 'URL webhook',
            webhookSecret: 'Секрет webhook',
            savedMask: 'Збережений токен',
            secretConfigured: 'Налаштовано',
            secretMissing: 'Не задано',
            status: 'Статус',
            notConfigured: 'Не налаштовано',
            tokenSaved: 'Токен збережено',
            connected: 'Підключено',
            webhookSet: 'Webhook задано',
            error: 'Помилка',
        },
    };
    const t = text[locale] ?? text.en;

    const statusLabel = (() => {
        if (telegram.last_error) return t.error;
        if (telegram.is_webhook_set) return t.webhookSet;
        if (telegram.is_connected) return t.connected;
        if (telegram.has_bot_token) return t.tokenSaved;
        return t.notConfigured;
    })();

    const statusClass = (() => {
        if (telegram.last_error) return 'bg-red-100 text-red-700';
        if (telegram.is_webhook_set && telegram.is_connected) return 'bg-emerald-100 text-emerald-700';
        if (telegram.has_bot_token) return 'bg-amber-100 text-amber-800';
        return 'bg-slate-100 text-slate-700';
    })();

    const run = (action, callback) => {
        setBusyAction(action);
        callback({
            preserveScroll: true,
            onFinish: () => setBusyAction(null),
        });
    };

    return (
        <div className="space-y-6">
            <div className="app-widget p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <p className="text-sm text-slate-600">{t.subtitle}</p>
                    <span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusClass}`}>
                        {t.status}: {statusLabel}
                    </span>
                </div>

                <div className="mt-4 space-y-3">
                    <label className="block text-sm font-medium text-slate-700">{t.token}</label>
                    <input
                        type="password"
                        placeholder={telegram.has_bot_token ? '••••••••' : ''}
                        value={token}
                        onChange={(e) => setToken(e.target.value)}
                        className="block h-10 w-full max-w-xl rounded-lg border border-slate-300 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                    />
                    {telegram.bot_token_masked && (
                        <p className="text-xs text-slate-500">
                            {t.savedMask}: <span className="font-medium">{telegram.bot_token_masked}</span>
                        </p>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            disabled={busyAction !== null || !token.trim()}
                            onClick={() =>
                                run('save', (opts) =>
                                    router.post(
                                        route('settings.telegram.save-token'),
                                        { bot_token: token },
                                        {
                                            ...opts,
                                            onSuccess: () => setToken(''),
                                        },
                                    ),
                                )
                            }
                            className="inline-flex h-9 items-center gap-2 rounded-lg bg-indigo-600 px-3 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-60"
                        >
                            {busyAction === 'save' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                            {t.saveToken}
                        </button>
                        <button
                            type="button"
                            disabled={busyAction !== null || !telegram.has_bot_token}
                            onClick={() =>
                                run('check', (opts) =>
                                    router.post(route('settings.telegram.check'), {}, opts),
                                )
                            }
                            className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                        >
                            <PlugZap className="h-4 w-4" />
                            {t.check}
                        </button>
                        <button
                            type="button"
                            disabled={busyAction !== null || !telegram.has_bot_token}
                            onClick={() =>
                                run('webhook', (opts) =>
                                    router.post(route('settings.telegram.set-webhook'), {}, opts),
                                )
                            }
                            className="inline-flex h-9 items-center gap-2 rounded-lg bg-emerald-600 px-3 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                        >
                            <Webhook className="h-4 w-4" />
                            {t.setWebhook}
                        </button>
                        <button
                            type="button"
                            disabled={busyAction !== null || !telegram.has_bot_token}
                            onClick={() =>
                                run('remove', (opts) =>
                                    router.post(route('settings.telegram.remove-webhook'), {}, opts),
                                )
                            }
                            className="inline-flex h-9 items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 text-sm font-medium text-red-700 transition hover:bg-red-100 disabled:opacity-60"
                        >
                            <Power className="h-4 w-4" />
                            {t.removeWebhook}
                        </button>
                    </div>
                </div>
            </div>

            <div className="app-widget grid gap-3 p-4 sm:grid-cols-2">
                <div>
                    <p className="text-xs uppercase tracking-wide text-slate-500">{t.username}</p>
                    <p className="mt-1 text-sm text-slate-900">{telegram.bot_username ?? '—'}</p>
                </div>
                <div>
                    <p className="text-xs uppercase tracking-wide text-slate-500">{t.webhookSecret}</p>
                    <p className="mt-1 text-sm text-slate-900">
                        {telegram.has_webhook_secret ? t.secretConfigured : t.secretMissing}
                    </p>
                </div>
                <div className="sm:col-span-2">
                    <p className="text-xs uppercase tracking-wide text-slate-500">{t.webhookUrl}</p>
                    <p className="mt-1 break-all text-sm text-slate-900">{telegram.webhook_url ?? '—'}</p>
                </div>
            </div>

            {telegram.last_error ? (
                <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                    {telegram.last_error}
                </div>
            ) : null}
            {errors.telegram ? <p className="text-sm text-red-600">{errors.telegram}</p> : null}
        </div>
    );
}
