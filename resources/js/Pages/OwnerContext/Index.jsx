import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from '@/locales/useTranslation';

export default function OwnerContextIndex() {
    const { t } = useTranslation();
    const { counts = {}, sources = [] } = usePage().props;

    return (
        <AdminLayout title={t('settings.ownerContext.title')}>
            <Head title={t('settings.ownerContext.title')} />
            <div className="space-y-4">
                <p className="text-sm text-slate-600">{t('settings.ownerContext.adminIntro')}</p>
                <dl className="grid grid-cols-2 gap-2 md:grid-cols-3">
                    {Object.entries(counts).map(([key, value]) => (
                        <div key={key} className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] px-3 py-3">
                            <dt className="text-xs text-slate-500">{key}</dt>
                            <dd className="text-lg font-medium text-slate-900">{value}</dd>
                        </div>
                    ))}
                </dl>
                <ul className="space-y-2">
                    {sources.map((source) => (
                        <li key={source.id} className="rounded-xl border border-[#E6DCC8] bg-white px-3 py-3 text-sm text-slate-800">
                            {source.name} · {source.status} · {source.extracted} · {source.accepted} · {source.needs_review}
                            {source.error_category ? ` · ${source.error_category}` : ''}
                        </li>
                    ))}
                </ul>
            </div>
        </AdminLayout>
    );
}
