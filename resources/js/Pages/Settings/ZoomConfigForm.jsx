import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function ZoomConfigForm() {
    const { integrations = {}, errors = {} } = usePage().props;
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
            <h3 className="text-sm font-semibold text-slate-900">Zoom Configuration</h3>
            <p className="text-xs text-slate-500">
                Server-to-Server OAuth. Webhook URL: {zoom.webhook_url}
            </p>
            <label className="block text-sm text-slate-700">
                Account ID
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
                Client ID
                <input
                    type="text"
                    autoComplete="off"
                    value={clientId}
                    onChange={(event) => setClientId(event.target.value)}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            <label className="block text-sm text-slate-700">
                Client Secret
                <input
                    type="password"
                    autoComplete="new-password"
                    value={clientSecret}
                    onChange={(event) => setClientSecret(event.target.value)}
                    placeholder={zoom.has_client_secret ? '••••••••••••' : ''}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            <p className="text-xs text-slate-500">Leave blank to keep the current secret.</p>
            <label className="block text-sm text-slate-700">
                Webhook secret token
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
                Enabled
            </label>
            <div className="flex flex-wrap gap-2">
                <button
                    type="submit"
                    disabled={busy !== null}
                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    Save Zoom configuration
                </button>
                <button
                    type="button"
                    disabled={busy !== null || !zoom.configured}
                    onClick={() => post('settings.integrations.zoom.test', {})}
                    className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                >
                    Test Zoom Connection
                </button>
                <button
                    type="button"
                    disabled={busy !== null}
                    onClick={() => post('settings.integrations.zoom.disconnect', {})}
                    className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                >
                    Disconnect
                </button>
            </div>
        </form>
    );
}
