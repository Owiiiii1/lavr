import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

export default function ProjectShow({ project, admin_href }) {
    const { t } = useTranslation();

    return (
        <LavrAppShell>
            <Head title={project?.name || t('projects.project')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/projects" className="text-sm text-sky-300">
                    {t('projects.all')}
                </Link>
                <h1 className="mt-3 text-2xl font-semibold text-white">{project?.name}</h1>
                <p className="mt-2 text-sm text-slate-400">{t('projects.showHint')}</p>
                {project?.description ? (
                    <p className="mt-4 text-sm leading-6 text-slate-200">{project.description}</p>
                ) : (
                    <p className="mt-4 text-sm text-slate-500">{t('projects.noDescription')}</p>
                )}
                <p className="mt-4 text-xs uppercase tracking-[0.14em] text-slate-500">{project?.status}</p>
                {admin_href ? (
                    <a href={admin_href} className="mt-6 inline-block text-sm text-sky-300">
                        {t('projects.openFull')}
                    </a>
                ) : null}
            </div>
        </LavrAppShell>
    );
}
