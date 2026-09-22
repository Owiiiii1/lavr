import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import GoogleOAuthConfigForm from './GoogleOAuthConfigForm';
import IntegrationProviderCard from './IntegrationProviderCard';
import SettingsPanelHeader from './SettingsPanelHeader';
import { translateKnown } from './integrationCopy';

export default function GooglePanel({ t, status }) {
    const { integrations = {}, locale = 'en' } = usePage().props;
    const providers = integrations.providers ?? [];
    const accounts = integrations.google_accounts ?? [];
    const provider = providers.find((item) => item.provider === 'google');
    const [disconnecting, setDisconnecting] = useState(null);
    const tr = (value, fallback = '') => translateKnown(locale, value) || fallback;

    const confirmDisconnect = () => window.confirm(t.disconnectConfirm);

    const disconnectProvider = () => {
        if (disconnecting || !confirmDisconnect()) {
            return;
        }

        setDisconnecting('google');
        router.post(route('integrations.google.disconnect'), {}, {
            preserveScroll: true,
            onFinish: () => setDisconnecting(null),
        });
    };

    const disconnectAccount = (accountId) => {
        if (disconnecting || !confirmDisconnect()) {
            return;
        }

        setDisconnecting(`google-${accountId}`);
        router.post(route('integrations.google.disconnect'), { account_id: accountId }, {
            preserveScroll: true,
            onFinish: () => setDisconnecting(null),
        });
    };

    const testAccount = (accountId) => {
        router.post(route('integrations.google.test', accountId), {}, { preserveScroll: true });
    };

    const setAccountEnabled = (accountId, enabled) => {
        router.patch(route('integrations.google.update', accountId), { enabled }, { preserveScroll: true });
    };

    return (
        <div className="space-y-4">
            <SettingsPanelHeader title={t.tabGoogle} hint={t.hintGoogle} status={status} t={t} />

            {provider ? (
                <IntegrationProviderCard
                    provider={provider}
                    t={t}
                    disconnecting={disconnecting}
                    onDisconnect={disconnectProvider}
                >
                    <GoogleOAuthConfigForm />
                </IntegrationProviderCard>
            ) : (
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <GoogleOAuthConfigForm />
                </section>
            )}

            <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 className="text-base font-semibold text-slate-900">{t.googleAccounts}</h3>
                    <a href={route('integrations.google.connect')} className="text-sm font-medium text-indigo-700">
                        {t.addGoogle}
                    </a>
                </div>
                {accounts.length === 0 ? (
                    <p className="text-sm text-slate-500">{t.noGoogleAccounts}</p>
                ) : (
                    <ul className="space-y-3">
                        {accounts.map((account) => (
                            <li
                                key={account.id}
                                className="rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-semibold text-slate-900">{account.label}</p>
                                        <p className="text-slate-600">{account.email}</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {account.gmail_connected ? 'Gmail' : t.noGmail}
                                            {' · '}
                                            {account.calendar_connected ? 'Calendar' : t.noCalendar}
                                            {' · '}
                                            {tr(account.health || account.status)}
                                            {account.last_success_at ? ` · ${t.lastSync}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            onClick={() => testAccount(account.id)}
                                            className="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium"
                                        >
                                            {t.test}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setAccountEnabled(account.id, !account.enabled)}
                                            className="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium"
                                        >
                                            {account.enabled ? t.disable : t.enable}
                                        </button>
                                        <a
                                            href={route('integrations.google.connect')}
                                            className="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium"
                                        >
                                            {t.reconnect}
                                        </a>
                                        <button
                                            type="button"
                                            onClick={() => disconnectAccount(account.id)}
                                            disabled={disconnecting !== null}
                                            className="rounded-lg border border-slate-300 px-2 py-1 text-xs font-medium disabled:opacity-60"
                                        >
                                            {t.disconnect}
                                        </button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
