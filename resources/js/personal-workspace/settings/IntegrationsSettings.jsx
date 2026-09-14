import { Link } from '@inertiajs/react';
import { useState } from 'react';
import SettingsCard from '@/personal-workspace/settings/SettingsCard';

const RESPONSE_MODE_LABELS = {
    text: 'текст',
    voice: 'голос',
    auto: 'авто',
};

function integrationDot(state) {
    if (state === 'connected' || state === 'enabled') {
        return 'bg-emerald-400';
    }
    if (state === 'error' || state === 'revoked') {
        return 'bg-rose-400';
    }
    if (state === 'permission_required' || state === 'incomplete') {
        return 'bg-amber-400';
    }
    return 'bg-slate-500';
}

function statusLabel(state, fallback) {
    if (state === 'connected' || state === 'enabled') {
        return 'Connected';
    }
    if (state === 'not_connected' || state === 'disconnected') {
        return 'Not connected';
    }
    return fallback || state || 'Not connected';
}

function IntegrationCard({ title, status, detail, actions, children, expanded, onToggle }) {
    return (
        <article className="rounded-2xl border border-white/10 bg-white/5 p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className={`h-2 w-2 shrink-0 rounded-full ${integrationDot(status)}`} />
                        <h3 className="text-sm font-semibold text-white">{title}</h3>
                    </div>
                    <p className="mt-1 text-xs text-slate-400">{detail}</p>
                </div>
                <div className="flex shrink-0 flex-wrap justify-end gap-2">
                    {actions}
                    {children ? (
                        <button
                            type="button"
                            onClick={onToggle}
                            className="rounded-lg border border-white/10 px-3 py-1.5 text-xs font-medium text-slate-200 hover:bg-white/5"
                        >
                            {expanded ? 'Скрыть' : 'Manage'}
                        </button>
                    ) : null}
                </div>
            </div>
            {expanded && children ? <div className="mt-3 border-t border-white/10 pt-3">{children}</div> : null}
        </article>
    );
}

function TelegramPairingCard({ telegram }) {
    const [expanded, setExpanded] = useState(false);

    if (!telegram) {
        return null;
    }

    return (
        <IntegrationCard
            title="Telegram pairing"
            status={telegram.connected ? 'connected' : 'not_connected'}
            detail={telegram.connected
                ? `Connected as ${telegram.account_label || 'Telegram'}`
                : 'Not connected'}
            expanded={expanded}
            onToggle={() => setExpanded((value) => !value)}
        >
            <div className="space-y-2 text-sm text-slate-300">
                <p>
                    Режим ответа: {RESPONSE_MODE_LABELS[telegram.response_mode] || telegram.response_mode || 'текст'}.
                    Сменить можно в чате: «отвечай голосом» или «отвечай текстом».
                </p>
                {telegram.connected ? null : (
                    <p>
                        Чтобы связать Telegram, отправьте боту код сопряжения
                        {telegram.access_code ? `: ${telegram.access_code}` : '.'}
                    </p>
                )}
            </div>
        </IntegrationCard>
    );
}

export default function IntegrationsSettings({ integrations = [], googleAccounts = [], telegram, capabilities }) {
    const [openProvider, setOpenProvider] = useState(null);
    const ownerCards = capabilities.integrations ? integrations : [];

    if (!capabilities.integrations && !telegram) {
        return (
            <SettingsCard title="Интеграции" description="Для этого аккаунта нет доступных каналов.">
                <p className="text-sm text-slate-400">Пусто.</p>
            </SettingsCard>
        );
    }

    return (
        <div className="space-y-3">
            <div>
                <h3 className="text-sm font-semibold text-white">Интеграции</h3>
                <p className="mt-1 text-xs leading-5 text-slate-400">
                    {capabilities.integrations
                        ? 'Статус подключений. Подключение и секреты настраиваются в Admin.'
                        : 'Личные каналы этого аккаунта. Owner-интеграции сюда не попадают.'}
                </p>
            </div>

            {ownerCards.map((item) => {
                const capabilityLine = (item.capabilities || [])
                    .map((capability) => capability.label)
                    .filter(Boolean)
                    .join(' · ');
                const connected = item.state === 'connected' || item.state === 'enabled';

                return (
                    <IntegrationCard
                        key={item.provider}
                        title={item.display_name}
                        status={item.state}
                        detail={[
                            statusLabel(item.state, item.label),
                            capabilityLine || item.account_label || item.label,
                        ].filter(Boolean).join(' · ')}
                        actions={capabilities.integrations ? (
                            <Link
                                href={route('settings.index', { tab: 'integrations' })}
                                className="rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-400"
                            >
                                {connected ? 'Manage' : 'Connect'}
                            </Link>
                        ) : null}
                        expanded={openProvider === item.provider}
                        onToggle={() => setOpenProvider((current) => (current === item.provider ? null : item.provider))}
                    >
                        <p className="text-xs text-slate-400">
                            {item.account_label || item.label}
                        </p>
                    </IntegrationCard>
                );
            })}

            <TelegramPairingCard telegram={telegram} />

            {googleAccounts.length > 0 ? (
                <div className="space-y-2">
                    {googleAccounts.map((account) => (
                        <article key={account.id} className="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <p className="text-sm font-semibold text-white">{account.label}</p>
                            <p className="mt-1 text-xs text-slate-400">
                                {account.email}
                                {' · '}
                                {account.health === 'blocked' ? 'Needs attention' : 'Connected'}
                            </p>
                        </article>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
