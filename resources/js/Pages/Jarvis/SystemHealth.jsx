import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

function statusClass(status) {
    if (status === 'healthy' || status === 'ready' || status === 'pass') {
        return 'text-emerald-300';
    }
    if (status === 'blocked' || status === 'fail') {
        return 'text-rose-300';
    }
    return 'text-amber-200';
}

export default function SystemHealth({ health }) {
    const { t } = useTranslation();
    const checklist = health?.checklist || [];
    const checks = health?.checks || {};

    return (
        <LavrAppShell>
            <Head title={t('health.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">{t('health.title')}</h1>
                <p className="mt-2 text-sm text-slate-400">{t('health.intro')}</p>
                <ul className="mt-6 space-y-2">
                    {checklist.map((item) => (
                        <li key={item.key} className="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                            <p className={`text-xs uppercase tracking-[0.14em] ${statusClass(item.state)}`}>{t(`health.state_${item.state}`)}</p>
                            <p className="mt-1 text-sm text-white">{item.label}</p>
                            <p className="mt-1 text-xs text-slate-400">{item.detail}</p>
                        </li>
                    ))}
                </ul>
                <div className="mt-8 space-y-2 text-sm">
                    {['google', 'zoom', 'ai', 'queue', 'scheduler'].map((key) => (
                        <p key={key} className="rounded-xl bg-black/20 px-3 py-2">
                            <span className={statusClass(checks[key]?.status)}>{t(`health.status_${checks[key]?.status || 'not_configured'}`)}</span>
                            {' · '}
                            {checks[key]?.message}
                        </p>
                    ))}
                </div>
                <Link href="/lavr?settings=integrations" className="mt-6 inline-flex min-h-11 items-center text-sm text-sky-300">{t('health.integrations')}</Link>
            </div>
        </LavrAppShell>
    );
}
