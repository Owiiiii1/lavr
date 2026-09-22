import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function ZoomConfigForm() {
    const { integrations = {}, errors = {}, locale = 'en' } = usePage().props;
    const text = {
        en: {
            title: 'Zoom Configuration',
            intro: 'Server-to-Server OAuth. Webhook URL:',
            accountId: 'Account ID',
            clientId: 'Client ID',
            clientSecret: 'Client Secret',
            keepSecret: 'Leave blank to keep the current secret.',
            webhookSecret: 'Webhook secret token',
            enabled: 'Enabled',
            save: 'Save Zoom configuration',
            test: 'Test Zoom Connection',
            disconnect: 'Disconnect',
        },
        ru: {
            title: 'Настройки Zoom',
            intro: 'Server-to-Server OAuth. URL webhook:',
            accountId: 'Account ID',
            clientId: 'Client ID',
            clientSecret: 'Client Secret',
            keepSecret: 'Оставьте пустым, чтобы сохранить текущий секрет.',
            webhookSecret: 'Секрет webhook',
            enabled: 'Включено',
            save: 'Сохранить настройки Zoom',
            test: 'Проверить подключение Zoom',
            disconnect: 'Отключить',
        },
        uk: {
            title: 'Налаштування Zoom',
            intro: 'Server-to-Server OAuth. URL webhook:',
            accountId: 'Account ID',
            clientId: 'Client ID',
            clientSecret: 'Client Secret',
            keepSecret: 'Залиште порожнім, щоб зберегти поточний секрет.',
            webhookSecret: 'Секрет webhook',
            enabled: 'Увімкнено',
            save: 'Зберегти налаштування Zoom',
            test: 'Перевірити підключення Zoom',
            disconnect: 'Відключити',
        },
    };
    const t = text[locale] ?? text.en;
    const zoom = integrations.zoom ?? {};
    const [accountId, setAccountId] = useState(zoom.account_id ?? '');
    const [clientId, setClientId] = useState(zoom.client_id ?? '');
    const [clientSecret, setClientSecret] = useState('');
    const [webhookSecret, setWebhookSecret] = useState('');
    const [enabled, setEnabled] = useState(Boolean(zoom.enabled));
    const [busy, setBusy] = useState(null);

    useEffect(() => {
        setAccountId(zoom.account_id ?? '');
        setClientId(zoom.client_id ?? '');
        setEnabled(Boolean(zoom.enabled));
        setClientSecret('');
        setWebhookSecret('');
    }, [zoom.account_id, zoom.client_id, zoom.enabled, zoom.has_client_secret, zoom.has_webhook_secret]);

    const post = (namedRoute, payload) => {
        if (busy) {
            return;
        }

        setBusy(namedRoute);
        router.post(route(namedRoute), payload, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
            onSuccess: () => {
                setClientSecret('');
                setWebhookSecret('');
            },
        });
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                post('settings.integrations.zoom.update', {
                    account_id: accountId,
                    client_id: clientId,
                    client_secret: clientSecret,
                    webhook_secret: webhookSecret,
                    enabled: enabled ? 1 : 0,
                });
            }}
            className="mt-4 space-y-3 border-t border-[#E6DCC8] pt-4"
        >
            <h3 className="text-sm font-semibold text-slate-900">{t.title}</h3>
            <p className="text-xs text-slate-500">
                {t.intro} {zoom.webhook_url}
            </p>
            <label className="block text-sm text-slate-700">
                {t.accountId}
                <input
                    type="text"
                    autoComplete="off"
                    value={accountId}
                    onChange={(event) => setAccountId(event.target.value)}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            {errors.account_id ? <p className="text-sm text-red-700">{errors.account_id}</p> : null}
            <label className="block text-sm text-slate-700">
                {t.clientId}
                <input
                    type="text"
                    autoComplete="off"
                    value={clientId}
                    onChange={(event) => setClientId(event.target.value)}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            <label className="block text-sm text-slate-700">
                {t.clientSecret}
                <input
                    type="password"
                    autoComplete="new-password"
                    value={clientSecret}
                    onChange={(event) => setClientSecret(event.target.value)}
                    placeholder={zoom.has_client_secret ? '••••••••••••' : ''}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            <p className="text-xs text-slate-500">{t.keepSecret}</p>
            <label className="block text-sm text-slate-700">
                {t.webhookSecret}
                <input
                    type="password"
                    autoComplete="new-password"
                    value={webhookSecret}
                    onChange={(event) => setWebhookSecret(event.target.value)}
                    placeholder={zoom.has_webhook_secret ? '••••••••••••' : ''}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" checked={enabled} onChange={(event) => setEnabled(event.target.checked)} />
                {t.enabled}
            </label>
            <div className="flex flex-wrap gap-2">
                <button
                    type="submit"
                    disabled={busy !== null}
                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {t.save}
                </button>
                <button
                    type="button"
                    disabled={busy !== null || !zoom.configured}
                    onClick={() => post('settings.integrations.zoom.test', {})}
                    className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                >
                    {t.test}
                </button>
                <button
                    type="button"
                    disabled={busy !== null}
                    onClick={() => post('settings.integrations.zoom.disconnect', {})}
                    className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                >
                    {t.disconnect}
                </button>
            </div>
        </form>
    );
}
