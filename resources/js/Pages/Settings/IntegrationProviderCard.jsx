import { usePage } from '@inertiajs/react';
import { translateKnown } from './integrationCopy';
import { ATTENTION, OFF, badgeClass, normalizeState } from './settingsStatus';

function statusClass(state, configured) {
    const normalized = normalizeState(state);

    return badgeClass(normalized === OFF && configured ? ATTENTION : normalized);
}

function actionAvailable(provider, key) {
    return (provider.actions ?? []).some((action) => action.key === key && action.available);
}

export default function IntegrationProviderCard({ provider, t, disconnecting, onDisconnect, children, className = '' }) {
    const { locale = 'en' } = usePage().props;
    const tr = (value) => translateKnown(locale, value);

    return (
        <section className={`rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4 ${className}`.trim()}>
            <div className="flex items-start justify-between gap-3">
                <h2 className="text-base font-semibold text-slate-900">{provider.display_name}</h2>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(provider.state, provider.provider === 'google' && provider.configured)}`}>
                    {tr(provider.label)}
                </span>
            </div>
            {(provider.oauth_client_label || provider.account_status_label) && (
                <ul className="mt-2 space-y-1 text-sm text-slate-600">
                    {provider.oauth_client_label && (
                        <li>{t.oauthClient}: {tr(provider.oauth_client_label)}</li>
                    )}
                    {provider.account_status_label && (
                        <li>{t.account}: {tr(provider.account_status_label)}</li>
                    )}
                </ul>
            )}
            {provider.account_label && provider.account_status_label !== provider.account_label && (
                <p className="mt-2 text-sm text-slate-700">{provider.account_label}</p>
            )}
            {provider.capability_states?.length > 0 && (
                <ul className="mt-2 space-y-1 text-sm text-slate-600">
                    {provider.capability_states.map((item) => (
                        <li key={item.key}>
                            {tr(item.label)}: {tr(item.state)}
                        </li>
                    ))}
                </ul>
            )}
            {provider.scope_labels?.length > 0 && (
                <p className="mt-1 text-sm text-slate-600">
                    {t.scopes}: {provider.scope_labels.join(', ')}
                </p>
            )}
            {provider.connected_at && (
                <p className="mt-1 text-sm text-slate-600">
                    {t.connectedAt}: {provider.connected_at}
                </p>
            )}
            {provider.token_health && (
                <p className="mt-1 text-sm text-slate-600">
                    {t.tokenHealth}: {tr(provider.token_health)}
                </p>
            )}
            {provider.diagnostic_message && (
                <p className="mt-2 text-sm text-slate-600">{tr(provider.diagnostic_message)}</p>
            )}
            {provider.provider === 'google' && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {actionAvailable(provider, 'connect') && (
                        <a
                            href={route('integrations.google.connect')}
                            className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            {t.connect}
                        </a>
                    )}
                    {actionAvailable(provider, 'reconnect') && (
                        <a
                            href={route('integrations.google.connect')}
                            className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            {t.reconnect}
                        </a>
                    )}
                    {actionAvailable(provider, 'enable_calendar') && (
                        <a
                            href={route('integrations.google.connect', { intent: 'calendar' })}
                            className="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-700"
                        >
                            {t.enableCalendar}
                        </a>
                    )}
                    {actionAvailable(provider, 'enable_gmail') && (
                        <a
                            href={route('integrations.google.connect', { intent: 'gmail' })}
                            className="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-700"
                        >
                            {t.enableGmail}
                        </a>
                    )}
                    {!provider.configured && (
                        <button
                            type="button"
                            disabled
                            className="inline-flex h-9 items-center rounded-lg bg-slate-200 px-3 text-sm font-medium text-slate-500"
                        >
                            {t.connect}
                        </button>
                    )}
                    {actionAvailable(provider, 'disconnect') && (
                        <button
                            type="button"
                            onClick={() => onDisconnect('google')}
                            disabled={disconnecting !== null}
                            className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                        >
                            {t.disconnect}
                        </button>
                    )}
                </div>
            )}
            {children}
        </section>
    );
}
