import LavrAppShell from '@/telegram/LavrAppShell';
import { Head, Link } from '@inertiajs/react';

export default function WorkspaceProjects({ projects = [], hint }) {
    return (
        <LavrAppShell>
            <Head title="Projects" />
            <div className="jarvis-workspace min-h-[100dvh] px-4 pb-6 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">Projects</h1>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-400">{hint}</p>

                {projects.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">Пока нет проектов.</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <Link
                                    href={route('jarvis.workspace.projects.show', project.id)}
                                    className="block rounded-2xl border border-white/10 bg-white/5 px-4 py-3"
                                >
                                    <p className="text-sm font-medium text-white">{project.name}</p>
                                    <p className="mt-1 text-xs text-slate-400">{project.status}</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </LavrAppShell>
    );
}
