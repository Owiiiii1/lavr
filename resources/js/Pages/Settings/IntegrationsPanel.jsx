import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import GoogleOAuthConfigForm from './GoogleOAuthConfigForm';
import IntegrationActivityPanel from './IntegrationActivityPanel';
import IntegrationProviderCard from './IntegrationProviderCard';
import TelegramPanel from './TelegramPanel';
import VoicePanel from './VoicePanel';
import WebResearchPanel from './WebResearchPanel';
import ZoomConfigForm from './ZoomConfigForm';

const SECTIONS = ['overview', 'web-research', 'voice', 'telegram', 'activity'];

function tileStatusClass(state) {
    if (state === 'ready' || state === 'connected' || state === 'enabled') {
        return 'bg-emerald-100 text-emerald-700';
    }
    if (state === 'not_configured' || state === 'incomplete' || state === 'connecting' || state === 'partial') {
        return 'bg-amber-100 text-amber-800';
    }
    if (state === 'error' || state === 'revoked') {
        return 'bg-red-100 text-red-700';
    }

    return 'bg-slate-100 text-slate-700';
}

export default function IntegrationsPanel() {
    const {
        integrations = {},
        webResearch = {},
        voice = {},
        telegram = {},
        locale = 'en',
        flash = {},
        section: initialSection = 'overview',
    } = usePage().props;
    const providers = integrations.providers ?? [];
    const googleAccounts = integrations.google_accounts ?? [];
    const telegramGroups = integrations.telegram_groups ?? [];
    const executions = integrations.recent_executions ?? [];
    const [disconnecting, setDisconnecting] = useState(null);
    const [section, setSection] = useState(SECTIONS.includes(initialSection) ? initialSection : 'overview');

    useEffect(() => {
        setSection(SECTIONS.includes(initialSection) ? initialSection : 'overview');
    }, [initialSection]);

    const text = {
        en: {
            hint: 'Connected accounts stay here. Telegram, Web Research, Voice/Speech, and the execution log have their own subsections.',
            overview: 'Overview',
            webResearch: 'Web Research',
            voice: 'Voice / Speech',
            telegram: 'Telegram',
            activity: 'Activity',
            open: 'Open',
            connect: 'Connect Google',
            connectGitHub: 'Connect GitHub',
            reconnect: 'Reconnect',
            disconnect: 'Disconnect',
            connectedAt: 'Connected',
            scopes: 'Scopes',
            tokenHealth: 'Token health',
            enableCalendar: 'Enable Calendar',
            enableGmail: 'Enable Gmail',
            telegramHint: 'Bot token, webhook, and connection status.',
            webResearchHint: 'Search provider, credentials status, and research limits.',
            voiceHint: 'STT/TTS providers and ElevenLabs configured status. Not Conversation AI.',
            activityHint: `${executions.length} recent tool executions`,
            telegramTitle: 'Telegram',
            addGoogle: 'Add Google account',
            disable: 'Disable',
            enable: 'Enable',
            test: 'Test',
            googleAccounts: 'Google accounts',
            telegramGroups: 'Telegram groups',
            lastSync: 'Last activity',
        },
        ru: {
            hint: 'Подключённые аккаунты остаются здесь. Telegram, Web Research, Voice/Speech и журнал выполнений вынесены в подразделы.',
            overview: 'Overview',
            webResearch: 'Web Research',
            voice: 'Voice / Speech',
            telegram: 'Telegram',
            activity: 'Activity',
            open: 'Open',
            connect: 'Connect Google',
            connectGitHub: 'Connect GitHub',
            reconnect: 'Reconnect',
            disconnect: 'Disconnect',
            connectedAt: 'Connected',
            scopes: 'Scopes',
            tokenHealth: 'Token health',
            enableCalendar: 'Enable Calendar',
            enableGmail: 'Enable Gmail',
            telegramHint: 'Токен бота, webhook и статус подключения.',
            webResearchHint: 'Провайдер поиска, статус credentials и лимиты research.',
            voiceHint: 'STT/TTS провайдеры и статус ElevenLabs. Не Conversation AI.',
            activityHint: `${executions.length} recent tool executions`,
            telegramTitle: 'Telegram',
            addGoogle: 'Добавить Google-аккаунт',
            disable: 'Отключить',
            enable: 'Включить',
            test: 'Проверить',
            googleAccounts: 'Google-аккаунты',
            telegramGroups: 'Группы Telegram',
            lastSync: 'Последняя активность',
        },
        uk: {
            hint: 'Підключені акаунти залишаються тут. Telegram, Web Research, Voice/Speech і журнал виконань винесені в підрозділи.',
            overview: 'Overview',
            webResearch: 'Web Research',
            voice: 'Voice / Speech',
            telegram: 'Telegram',
            activity: 'Activity',
            open: 'Open',
            connect: 'Connect Google',
            connectGitHub: 'Connect GitHub',
            reconnect: 'Reconnect',
            disconnect: 'Disconnect',
            connectedAt: 'Connected',
            scopes: 'Scopes',
            tokenHealth: 'Token health',
            enableCalendar: 'Enable Calendar',
            enableGmail: 'Enable Gmail',
            telegramHint: 'Токен бота, webhook і статус підключення.',
            webResearchHint: 'Провайдер пошуку, статус credentials і ліміти research.',
            voiceHint: 'STT/TTS провайдери і статус ElevenLabs. Не Conversation AI.',
            activityHint: `${executions.length} recent tool executions`,
            telegramTitle: 'Telegram',
            addGoogle: 'Додати Google-акаунт',
            disable: 'Вимкнути',
            enable: 'Увімкнути',
            test: 'Перевірити',
            googleAccounts: 'Google-акаунти',
            telegramGroups: 'Групи Telegram',
            lastSync: 'Остання активність',
        },
    };
    const t = text[locale] ?? text.en;

    const switchSection = (next) => {
        if (next === section) {
            return;
        }

        setSection(next);
        const query = { tab: 'integrations' };
        if (next !== 'overview') {
            query.section = next;
        }

        router.get(route('settings.index'), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const disconnectProvider = (provider) => {
        if (disconnecting) {
            return;
        }

        const namedRoute = provider === 'github'
            ? 'integrations.github.disconnect'
            : provider === 'zoom'
                ? 'settings.integrations.zoom.disconnect'
                : 'integrations.google.disconnect';

        setDisconnecting(provider);
        router.post(route(namedRoute), {}, {
            preserveScroll: true,
            onFinish: () => setDisconnecting(null),
        });
    };

    const disconnectAccount = (accountId) => {
        if (disconnecting) {
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

    const accountProviders = providers.filter((provider) => provider.provider !== 'telegram');
    const telegramProvider = providers.find((provider) => provider.provider === 'telegram');
    const telegramStatus = telegram.last_error
        ? 'error'
        : telegram.is_webhook_set
            ? 'connected'
            : telegram.has_bot_token
                ? 'connecting'
                : 'not_configured';
    const telegramLabel = telegram.last_error
        ? 'Error'
        : telegram.is_webhook_set
            ? 'Webhook set'
            : telegram.is_connected
                ? 'Connected'
                : telegram.has_bot_token
                    ? 'Token saved'
                    : (telegramProvider?.label ?? 'Not configured');

    const sections = [
        { id: 'overview', label: t.overview },
        { id: 'web-research', label: t.webResearch },
        { id: 'voice', label: t.voice },
        { id: 'telegram', label: t.telegram },
        { id: 'activity', label: t.activity },
    ];

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap gap-2">
                {sections.map((item) => {
                    const active = section === item.id;

                    return (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => switchSection(item.id)}
                            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition ${
                                active
                                    ? 'bg-slate-900 text-white'
                                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                            }`}
                        >
                            {item.label}
                        </button>
                    );
                })}
            </div>

            {section === 'overview' && <p className="text-sm text-slate-600">{t.hint}</p>}

            {flash.success && (
                <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</p>
            )}
            {flash.warning && (
                <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{flash.warning}</p>
            )}
            {flash.error && (
                <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{flash.error}</p>
            )}

            {section === 'overview' && (
                <div className="space-y-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        {accountProviders.map((provider) => (
                            <IntegrationProviderCard
                                key={provider.provider}
                                provider={provider}
                                t={t}
                                disconnecting={disconnecting}
                                onDisconnect={disconnectProvider}
                                className={['google', 'zoom'].includes(provider.provider) ? 'md:col-span-3' : ''}
                            >
                                {provider.provider === 'google' ? <GoogleOAuthConfigForm /> : null}
                                {provider.provider === 'zoom' ? <ZoomConfigForm /> : null}
                            </IntegrationProviderCard>
                        ))}
                    </div>

                    {googleAccounts.length > 0 && (
                        <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                            <div className="mb-3 flex items-center justify-between gap-3">
                                <h2 className="text-base font-semibold text-slate-900">{t.googleAccounts}</h2>
                                <a
                                    href={route('integrations.google.connect')}
                                    className="text-sm font-medium text-indigo-700"
                                >
                                    {t.addGoogle}
                                </a>
                            </div>
                            <ul className="space-y-3">
                                {googleAccounts.map((account) => (
                                    <li key={account.id} className="rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <p className="font-semibold text-slate-900">{account.label}</p>
                                                <p className="text-slate-600">{account.email}</p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {account.gmail_connected ? 'Gmail' : 'No Gmail'}
                                                    {' · '}
                                                    {account.calendar_connected ? 'Calendar' : 'No Calendar'}
                                                    {' · '}
                                                    {account.health || account.status}
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
                        </section>
                    )}

                    {telegramGroups.length > 0 && (
                        <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                            <h2 className="mb-3 text-base font-semibold text-slate-900">{t.telegramGroups}</h2>
                            <ul className="space-y-2 text-sm text-slate-700">
                                {telegramGroups.map((group) => (
                                    <li key={group.id}>
                                        {group.title}
                                        {group.monitoring_enabled ? ' · monitoring' : ''}
                                        {group.last_message_at ? ` · ${group.last_message_at}` : ''}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <div className="grid gap-4 md:grid-cols-3">
                        <button
                            type="button"
                            onClick={() => switchSection('web-research')}
                            className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4 text-left hover:border-slate-300"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="text-base font-semibold text-slate-900">{t.webResearch}</h2>
                                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tileStatusClass(webResearch.status)}`}>
                                    {webResearch.status_label ?? 'Disabled'}
                                </span>
                            </div>
                            <p className="mt-2 text-sm text-slate-600">{t.webResearchHint}</p>
                            <p className="mt-2 text-sm text-slate-700">
                                {webResearch.active_provider_label ?? 'Disabled'}
                            </p>
                            <p className="mt-3 text-sm font-medium text-indigo-700">{t.open}</p>
                        </button>

                        <button
                            type="button"
                            onClick={() => switchSection('voice')}
                            className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4 text-left hover:border-slate-300"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="text-base font-semibold text-slate-900">{t.voice}</h2>
                                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tileStatusClass(voice.status)}`}>
                                    {voice.status_label ?? 'Not configured'}
                                </span>
                            </div>
                            <p className="mt-2 text-sm text-slate-600">{t.voiceHint}</p>
                            <p className="mt-2 text-sm text-slate-700">
                                {voice.stt_provider_label ?? 'STT'} · {voice.tts_provider_label ?? 'TTS'}
                            </p>
                            <p className="mt-3 text-sm font-medium text-indigo-700">{t.open}</p>
                        </button>

                        <button
                            type="button"
                            onClick={() => switchSection('telegram')}
                            className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4 text-left hover:border-slate-300"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="text-base font-semibold text-slate-900">{t.telegramTitle}</h2>
                                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tileStatusClass(telegramStatus)}`}>
                                    {telegramLabel}
                                </span>
                            </div>
                            <p className="mt-2 text-sm text-slate-600">{t.telegramHint}</p>
                            <p className="mt-2 text-sm text-slate-700">
                                {telegram.bot_username ? `@${telegram.bot_username}` : telegramProvider?.account_label || 'Telegram'}
                            </p>
                            <p className="mt-3 text-sm font-medium text-indigo-700">{t.open}</p>
                        </button>

                        <button
                            type="button"
                            onClick={() => switchSection('activity')}
                            className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4 text-left hover:border-slate-300"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="text-base font-semibold text-slate-900">{t.activity}</h2>
                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {executions.length}
                                </span>
                            </div>
                            <p className="mt-2 text-sm text-slate-600">{t.activityHint}</p>
                            <p className="mt-3 text-sm font-medium text-indigo-700">{t.open}</p>
                        </button>
                    </div>
                </div>
            )}

            {section === 'web-research' && <WebResearchPanel />}

            {section === 'voice' && <VoicePanel />}

            {section === 'telegram' && (
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <div className="mb-4 flex items-start justify-between gap-3">
                        <h2 className="text-base font-semibold text-slate-900">{t.telegramTitle}</h2>
                        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tileStatusClass(telegramStatus)}`}>
                            {telegramLabel}
                        </span>
                    </div>
                    <TelegramPanel />
                </section>
            )}

            {section === 'activity' && <IntegrationActivityPanel />}
        </div>
    );
}
