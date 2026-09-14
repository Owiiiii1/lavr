import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function People() {
    const { t } = useTranslation();
    const { people = [], filters = {}, roleOptions = [], projects = [], pagination = {} } = usePage().props;

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('jarvis.people.index'),
            {
                q: event.target.q.value,
                role: event.target.role.value,
                project_id: event.target.project_id.value,
                status: event.target.status.value,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <LavrAppShell>
            <Head title={t('people.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">{t('people.title')}</h1>
                <form className="mt-4 grid gap-2 sm:grid-cols-2" onSubmit={applyFilters}>
                    <input
                        name="q"
                        defaultValue={filters.q || ''}
                        placeholder={t('people.searchPlaceholder')}
                        className="min-h-12 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white"
                    />
                    <select
                        name="role"
                        defaultValue={filters.role || ''}
                        className="min-h-12 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white"
                    >
                        <option value="">{t('people.allRoles')}</option>
                        {roleOptions.map((role) => (
                            <option key={role} value={role}>
                                {t(`people.role_${role}`)}
                            </option>
                        ))}
                    </select>
                    <select
                        name="project_id"
                        defaultValue={filters.project_id || ''}
                        className="min-h-12 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white"
                    >
                        <option value="">{t('people.allProjects')}</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                            </option>
                        ))}
                    </select>
                    <div className="flex gap-2">
                        <select
                            name="status"
                            defaultValue={filters.status || ''}
                            className="min-h-12 flex-1 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white"
                        >
                            <option value="">{t('people.allStatuses')}</option>
                            <option value="active">{t('people.status_active')}</option>
                            <option value="inactive">{t('people.status_inactive')}</option>
                            <option value="archived">{t('people.status_archived')}</option>
                        </select>
                        <button type="submit" className="min-h-12 rounded-2xl border border-white/10 px-4 text-sm">
                            {t('people.filter')}
                        </button>
                    </div>
                </form>

                {people.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">{t('people.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {people.map((person) => (
                            <li key={person.id}>
                                <Link
                                    href={route('jarvis.people.show', person.id)}
                                    className="block min-h-16 rounded-2xl border border-white/10 bg-white/5 px-4 py-3"
                                >
                                    <p className="text-sm font-medium text-white">{person.display_name}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {(person.roles || []).join(', ') || t('people.noRoles')}
                                        {person.position ? ` · ${person.position}` : ''}
                                        {person.primary_organization ? ` · ${person.primary_organization}` : ''}
                                    </p>
                                    {(person.projects || []).length > 0 ? (
                                        <p className="mt-2 flex flex-wrap gap-1">
                                            {person.projects.map((project) => (
                                                <span
                                                    key={project.id}
                                                    className="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-slate-200"
                                                >
                                                    {project.name}
                                                </span>
                                            ))}
                                        </p>
                                    ) : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {pagination.last_page > 1 ? (
                    <div className="mt-4 flex gap-3 text-sm text-slate-400">
                        {pagination.page > 1 ? (
                            <Link href={`/lavr/people?page=${pagination.page - 1}`} className="text-sky-300">{t('common.back')}</Link>
                        ) : null}
                        <span>{pagination.page} / {pagination.last_page}</span>
                        {pagination.page < pagination.last_page ? (
                            <Link href={`/lavr/people?page=${pagination.page + 1}`} className="text-sky-300">{t('common.next')}</Link>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </LavrAppShell>
    );
}
