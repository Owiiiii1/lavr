import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router } from '@inertiajs/react';

export default function More() {
    const { t } = useTranslation();
    const groups = [
        [
            { href: '/lavr/commitments', label: t('more.commitments'), hint: t('more.commitmentsHint') },
            { href: '/lavr/meetings', label: t('more.meetings'), hint: t('more.meetingsHint') },
            { href: '/lavr/briefs', label: t('more.briefs'), hint: t('more.briefsHint') },
            { href: '/lavr/leadership', label: t('more.leadership'), hint: t('more.leadershipHint') },
            { href: '/lavr/proactive', label: t('more.proactive'), hint: t('more.proactiveHint') },
            { href: '/lavr/reports', label: t('more.reports'), hint: t('more.reportsHint') },
        ],
        [
            { href: '/lavr?settings=knowledge', label: t('more.knowledge'), hint: t('more.knowledgeHint') },
            { href: '/lavr?settings=integrations', label: t('more.integrations'), hint: t('more.integrationsHint') },
            { href: '/lavr/system-health', label: t('more.health'), hint: t('more.healthHint') },
            { href: '/lavr/setup', label: t('more.setup'), hint: t('more.setupHint') },
            { href: '/lavr?settings=profile', label: t('more.settings'), hint: t('more.settingsHint') },
        ],
        [
            { href: '/lavr/notifications', label: t('more.notifications'), hint: t('more.notificationsHint') },
            { href: '/lavr/search', label: t('more.search'), hint: t('more.searchHint') },
            { href: '/lavr/organizations', label: t('more.organizations'), hint: t('more.organizationsHint') },
        ],
    ];

    return (
        <LavrAppShell>
            <Head title={t('more.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">{t('more.title')}</h1>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-400">
                    {t('more.intro')}
                </p>
                {groups.map((links) => (
                    <ul key={links[0].href} className="mt-6 space-y-2">
                        {links.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    className="flex min-h-14 items-center justify-between rounded-2xl border border-white/10 bg-white/5 px-4"
                                >
                                    <span className="text-sm font-medium text-white">{item.label}</span>
                                    <span className="text-xs text-slate-400">{item.hint}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                ))}
                <button
                    type="button"
                    className="mt-8 min-h-12 text-sm text-slate-400"
                    onClick={() => router.post(route('logout'))}
                >
                    {t('common.logout')}
                </button>
            </div>
        </LavrAppShell>
    );
}
