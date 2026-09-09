import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

export default function OrganizationShow({ organization }) {
    const { t } = useTranslation();

    return (
        <LavrAppShell>
            <Head title={organization?.name || t('organizations.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/organizations" className="text-sm text-sky-300">
                    {t('organizations.back')}
                </Link>
                <h1 className="mt-3 text-2xl font-semibold text-white">{organization?.name}</h1>
                <p className="mt-2 text-xs uppercase tracking-[0.14em] text-slate-500">{organization?.status}</p>
                {organization?.type ? <p className="mt-3 text-sm text-slate-300">{organization.type}</p> : null}
                {organization?.website ? <p className="mt-2 text-sm text-sky-300">{organization.website}</p> : null}
                {organization?.notes ? <p className="mt-4 text-sm leading-6 text-slate-200">{organization.notes}</p> : null}

                <section className="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4">
                    <h2 className="text-xs uppercase tracking-[0.14em] text-slate-500">{t('organizations.relatedProjects')}</h2>
                    {(organization?.projects || []).length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">{t('organizations.noProjects')}</p>
                    ) : (
                        <ul className="mt-2 space-y-2">
                            {organization.projects.map((project) => (
                                <li key={project.id}>
                                    <Link href={route('jarvis.workspace.projects.show', project.id)} className="text-sm text-sky-300">
                                        {project.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </LavrAppShell>
    );
}
