import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

export default function WorkspaceProjects({ projects = [], pagination = {} }) {
    const { t, bcp47 } = useTranslation();

    const activityLabel = (iso) => {
        if (!iso) {
            return '';
        }

        try {
            return new Intl.DateTimeFormat(bcp47, { dateStyle: 'medium' }).format(new Date(iso));
        } catch {
            return '';
        }
    };

    return (
        <LavrAppShell>
            <Head title={t('projects.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">{t('projects.title')}</h1>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-400">{t('projects.hint')}</p>

                {projects.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">{t('projects.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <Link
                                    href={route('jarvis.workspace.projects.show', project.id)}
                                    className="block min-h-16 rounded-2xl border border-white/10 bg-white/5 px-4 py-3"
                                >
                                    <p className="text-sm font-medium text-white">{project.name}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {project.status}
                                        {project.updated_at ? ` · ${activityLabel(project.updated_at)}` : ''}
                                    </p>
                                    <p className="mt-2 text-xs text-slate-500">
                                        {t('projects.peopleCount', { count: project.people_count ?? 0 })}
                                        {' · '}
                                        {t('projects.orgCount', { count: project.organizations_count ?? 0 })}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {pagination.last_page > 1 ? (
                    <p className="mt-4 text-sm text-slate-400">{pagination.page} / {pagination.last_page}</p>
                ) : null}
            </div>
        </LavrAppShell>
    );
}
