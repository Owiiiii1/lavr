import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head } from '@inertiajs/react';

export default function People({ phase = '4' }) {
    const { t } = useTranslation();

    return (
        <LavrAppShell>
            <Head title={t('people.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">{t('people.phase', { phase })}</p>
                <h1 className="mt-2 text-2xl font-semibold text-white">{t('people.title')}</h1>
                <p className="mt-3 max-w-lg text-sm leading-6 text-slate-300">{t('people.body')}</p>
                <p className="mt-6 max-w-lg text-sm leading-6 text-slate-500">
                    {t('people.extra')}
                </p>
            </div>
        </LavrAppShell>
    );
}
