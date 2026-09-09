import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import AiPanel from './AiPanel';
import AppPanel from './AppPanel';
import GeneralPanel from './GeneralPanel';
import IntegrationsPanel from './IntegrationsPanel';

export default function SettingsIndex() {
    const { locale = 'en', tab: initialTab = 'general' } = usePage().props;
    const [activeTab, setActiveTab] = useState(initialTab);

    useEffect(() => {
        setActiveTab(initialTab);
    }, [initialTab]);

    const text = {
        en: {
            pageTitle: 'Settings',
            general: 'General',
            ai: 'AI',
            app: 'App settings',
            integrations: 'Integrations',
        },
        ru: {
            pageTitle: 'Settings',
            general: 'General',
            ai: 'AI',
            app: 'App settings',
            integrations: 'Integrations',
        },
        uk: {
            pageTitle: 'Settings',
            general: 'General',
            ai: 'AI',
            app: 'App settings',
            integrations: 'Integrations',
        },
    };
    const t = text[locale] ?? text.en;

    const tabs = useMemo(
        () => [
            { id: 'general', label: t.general },
            { id: 'ai', label: t.ai },
            { id: 'app', label: t.app },
            { id: 'integrations', label: t.integrations },
        ],
        [t.ai, t.app, t.general, t.integrations],
    );

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

    return (
        <AdminLayout title={t.pageTitle}>
            <Head title={t.pageTitle} />

            <div className="space-y-6">
                <div className="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
                    {tabs.map((tab) => {
                        const active = activeTab === tab.id;

                        return (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => switchTab(tab.id)}
                                className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                                    active
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                }`}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </div>

                {activeTab === 'general' && <GeneralPanel />}
                {activeTab === 'ai' && <AiPanel />}
                {activeTab === 'app' && <AppPanel />}
                {activeTab === 'integrations' && <IntegrationsPanel />}
            </div>
        </AdminLayout>
    );
}
