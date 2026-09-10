import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router } from '@inertiajs/react';

export default function BriefsIndex({ briefs, filters }) {
    const { t } = useTranslation();
    const rows = briefs?.data || briefs || [];

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            '/lavr/briefs',
            {
                date: event.target.date.value,
                type: event.target.type.value,
                status: event.target.status.value,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <LavrAppShell>
            <Head title={t('brief.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">{t('common.lavr')}</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">{t('brief.title')}</h1>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-400">{t('brief.intro')}</p>

                <div className="mt-4 flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => router.post('/lavr/briefs/generate')}
                        className="inline-flex min-h-11 items-center rounded-2xl bg-sky-500/90 px-4 text-sm font-semibold text-white"
                    >
                        {t('brief.generate')}
                    </button>
                </div>

                <form className="mt-6 grid gap-2 sm:grid-cols-4" onSubmit={applyFilters}>
                    <input type="date" name="date" defaultValue={filters?.date || ''} className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm" />
                    <select name="type" defaultValue={filters?.type || ''} className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm">
                        <option value="">{t('brief.allTypes')}</option>
                        <option value="morning">{t('brief.morning')}</option>
                        <option value="evening">{t('brief.evening')}</option>
                        <option value="weekly">{t('brief.weekly')}</option>
                    </select>
                    <select name="status" defaultValue={filters?.status || ''} className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm">
                        <option value="">{t('brief.allStatuses')}</option>
                        <option value="ready">{t('brief.status_ready')}</option>
                        <option value="partial">{t('brief.status_partial')}</option>
                        <option value="failed">{t('brief.status_failed')}</option>
                    </select>
                    <button type="submit" className="rounded-xl border border-white/10 px-3 text-sm">{t('brief.filter')}</button>
                </form>

                {rows.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">{t('brief.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {rows.map((item) => (
                            <li key={item.id}>
                                <Link href={item.href || `/lavr/briefs/${item.id}`} className="block rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                    <p className="text-sm font-medium text-white">{item.generated_for} · {item.brief_type}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {item.status}
                                        {item.priority_count ? ` · ${item.priority_count}` : ''}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </LavrAppShell>
    );
}
