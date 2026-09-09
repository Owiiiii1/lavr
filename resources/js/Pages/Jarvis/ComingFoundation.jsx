import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head } from '@inertiajs/react';

export default function ComingFoundation({ kind = 'meetings', phase }) {
    const { t } = useTranslation();
    const title = kind === 'commitments' ? t('more.commitments') : t('more.meetings');
    const body = kind === 'commitments' ? t('more.commitmentsBody') : t('more.meetingsBody');

    return (
        <LavrAppShell>
            <Head title={title} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">{t('people.phase', { phase })}</p>
                <h1 className="mt-2 text-2xl font-semibold text-white">{title}</h1>
                <p className="mt-3 max-w-lg text-sm leading-6 text-slate-300">{body}</p>
                <p className="mt-6 max-w-lg text-sm leading-6 text-slate-500">
                    {t('more.reserved')}
                </p>
            </div>
        </LavrAppShell>
    );
}
