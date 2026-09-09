import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

export default function Organizations({ organizations = [] }) {
    const { t } = useTranslation();

    return (
        <LavrAppShell>
            <Head title={t('organizations.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">{t('organizations.title')}</h1>
                {organizations.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">{t('organizations.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {organizations.map((organization) => (
                            <li key={organization.id}>
                                <Link
                                    href={route('jarvis.organizations.show', organization.id)}
                                    className="block min-h-16 rounded-2xl border border-white/10 bg-white/5 px-4 py-3"
                                >
                                    <p className="text-sm font-medium text-white">{organization.name}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {organization.status}
                                        {organization.type ? ` · ${organization.type}` : ''}
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
