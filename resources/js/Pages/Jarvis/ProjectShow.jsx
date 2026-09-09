import LavrAppShell from '@/telegram/LavrAppShell';
import { Head, Link } from '@inertiajs/react';

export default function ProjectShow({ project, hint, admin_href }) {
    return (
        <LavrAppShell>
            <Head title={project?.name || 'Project'} />
            <div className="jarvis-workspace min-h-[100dvh] px-4 pb-6 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/projects" className="text-sm text-sky-300">
                    Все проекты
                </Link>
                <h1 className="mt-3 text-2xl font-semibold text-white">{project?.name}</h1>
                <p className="mt-2 text-sm text-slate-400">{hint}</p>
                {project?.description ? (
                    <p className="mt-4 text-sm leading-6 text-slate-200">{project.description}</p>
                ) : (
                    <p className="mt-4 text-sm text-slate-500">Нет описания.</p>
                )}
                <p className="mt-4 text-xs uppercase tracking-[0.14em] text-slate-500">{project?.status}</p>
                {admin_href ? (
                    <a href={admin_href} className="mt-6 inline-block text-sm text-sky-300">
                        Открыть полную карточку
                    </a>
                ) : null}
            </div>
        </LavrAppShell>
    );
}
