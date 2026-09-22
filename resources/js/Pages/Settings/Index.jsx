import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AiPanel from './AiPanel';
import GooglePanel from './GooglePanel';
import IntegrationActivityPanel from './IntegrationActivityPanel';
import OverviewPanel from './OverviewPanel';
import SettingsPanelHeader from './SettingsPanelHeader';
import TelegramSettingsPanel from './TelegramSettingsPanel';
import VoicePanel from './VoicePanel';
import WebResearchPanel from './WebResearchPanel';
import ZoomPanel from './ZoomPanel';
import { SETTINGS_TABS, settingsCopy } from './settingsCopy';
import { CONNECTION_ELEMENTS, READY, deriveSettingsStatus, dotClass } from './settingsStatus';

export default function SettingsIndex() {
    const page = usePage().props;
    const {
        locale = 'en',
        tab: initialTab = 'overview',
        flash = {},
        providers = [],
        aiRoles = [],
        telegram = {},
        webResearch = {},
        voice = {},
        integrations = {},
    } = page;
    const t = settingsCopy(locale);
    const [activeTab, setActiveTab] = useState(SETTINGS_TABS.includes(initialTab) ? initialTab : 'overview');

    useEffect(() => {
        setActiveTab(SETTINGS_TABS.includes(initialTab) ? initialTab : 'overview');
    }, [initialTab]);

    const statuses = useMemo(
        () => deriveSettingsStatus({ providers, aiRoles, telegram, webResearch, voice, integrations, locale, t }),
        [providers, aiRoles, telegram, webResearch, voice, integrations, locale, t],
    );

    const labels = {
        overview: t.tabOverview,
        ai: t.tabAi,
        telegram: t.tabTelegram,
        google: t.tabGoogle,
        zoom: t.tabZoom,
        voice: t.tabVoice,
        'web-research': t.tabWebResearch,
        activity: t.tabActivity,
    };

    const navGroups = [
        { title: null, items: ['overview'] },
        { title: t.groupAi, items: ['ai'] },
        { title: t.groupChannels, items: ['telegram'] },
        { title: t.groupSources, items: ['google', 'zoom'] },
        { title: t.groupAbilities, items: ['voice', 'web-research'] },
        { title: t.groupDiagnostics, items: ['activity'] },
    ];

    const working = CONNECTION_ELEMENTS.filter((id) => statuses[id]?.state === READY).length;

    const switchTab = (nextTab) => {
        if (nextTab === activeTab) {
            return;
        }

        setActiveTab(nextTab);
        router.get(
            route('settings.index'),
            { tab: nextTab },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const body = () => {
        if (activeTab === 'ai') {
            return (
                <div className="space-y-4">
                    <SettingsPanelHeader title={t.tabAi} hint={t.hintAi} status={statuses.ai} t={t} />
                    <AiPanel />
                </div>
            );
        }
        if (activeTab === 'telegram') {
            return <TelegramSettingsPanel t={t} status={statuses.telegram} />;
        }
        if (activeTab === 'google') {
            return <GooglePanel t={t} status={statuses.google} />;
        }
        if (activeTab === 'zoom') {
            return <ZoomPanel t={t} status={statuses.zoom} />;
        }
        if (activeTab === 'voice') {
            return <VoicePanel />;
        }
        if (activeTab === 'web-research') {
            return <WebResearchPanel />;
        }
        if (activeTab === 'activity') {
            return <IntegrationActivityPanel />;
        }

        return <OverviewPanel statuses={statuses} t={t} onOpen={switchTab} />;
    };

    const navButtonClass = (id) =>
        `flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition ${
            activeTab === id ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100'
        }`;

    const navMarker = (id) => {
        if (id === 'overview') {
            return (
                <span className={`text-xs font-semibold ${activeTab === id ? 'text-white/80' : 'text-slate-400'}`}>
                    {working}/{CONNECTION_ELEMENTS.length}
                </span>
            );
        }

        return (
            <span
                className={`h-2 w-2 shrink-0 rounded-full ${dotClass(statuses[id]?.state)}`}
                aria-hidden="true"
            />
        );
    };

    return (
        <AdminLayout title={t.pageTitle}>
            <Head title={t.pageTitle} />

            <div className="space-y-4">
                {flash.success && (
                    <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</p>
                )}
                {flash.warning && (
                    <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{flash.warning}</p>
                )}
                {flash.error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{flash.error}</p>}

                <div className="flex gap-2 overflow-x-auto pb-1 md:hidden">
                    {navGroups
                        .flatMap((group) => group.items)
                        .map((id) => (
                            <button
                                key={id}
                                type="button"
                                onClick={() => switchTab(id)}
                                className={`flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition ${
                                    activeTab === id
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'bg-slate-100 text-slate-700'
                                }`}
                            >
                                {id === 'overview' ? null : (
                                    <span
                                        className={`h-2 w-2 shrink-0 rounded-full ${dotClass(statuses[id]?.state)}`}
                                        aria-hidden="true"
                                    />
                                )}
                                {labels[id]}
                            </button>
                        ))}
                </div>

                <div className="flex gap-6">
                    <nav className="hidden w-56 shrink-0 flex-col gap-4 md:flex" aria-label={t.pageTitle}>
                        {navGroups.map((group) => (
                            <div key={group.title ?? 'root'} className="space-y-1">
                                {group.title ? (
                                    <p className="px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        {group.title}
                                    </p>
                                ) : null}
                                {group.items.map((id) => (
                                    <button
                                        key={id}
                                        type="button"
                                        onClick={() => switchTab(id)}
                                        className={navButtonClass(id)}
                                    >
                                        <span>{labels[id]}</span>
                                        {navMarker(id)}
                                    </button>
                                ))}
                            </div>
                        ))}
                    </nav>

                    <div className="min-w-0 flex-1">{body()}</div>
                </div>
            </div>
        </AdminLayout>
    );
}
