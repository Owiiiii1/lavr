import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

export default function Reports({ reports }) {
    const { t } = useTranslation();
    const items = reports?.items || [];

    return (
        <LavrAppShell>
            <Head title={t('reports.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">{t('common.lavr')}</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">{t('reports.title')}</h1>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-400">
                    {t('reports.intro')}
                </p>

                {items.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">{t('reports.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {items.map((item) => (
                            <li key={item.id} className="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                <p className="text-sm font-medium text-white">{item.name}</p>
                                {item.schedule_label ? (
                                    <p className="mt-1 text-xs text-slate-400">{item.schedule_label}</p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}

                <Link href="/lavr/more" className="mt-8 inline-flex min-h-11 items-center text-sm text-sky-300">
                    {t('reports.back')}
                </Link>
            </div>
        </LavrAppShell>
    );
}
