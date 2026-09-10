import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

export default function ProjectShow({ project, commitments = [], admin_href }) {
    const { t } = useTranslation();

    return (
        <LavrAppShell>
            <Head title={project?.name || t('projects.project')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/projects" className="text-sm text-sky-300">
                    {t('projects.all')}
                </Link>
                <h1 className="mt-3 text-2xl font-semibold text-white">{project?.name}</h1>
                <p className="mt-2 text-xs uppercase tracking-[0.14em] text-slate-500">{project?.status}</p>
                {project?.description ? (
                    <p className="mt-4 text-sm leading-6 text-slate-200">{project.description}</p>
                ) : (
                    <p className="mt-4 text-sm text-slate-500">{t('projects.noDescription')}</p>
                )}

                <Section title={t('projects.people')}>
                    {(project?.people || []).length === 0 ? (
                        <p className="text-slate-500">{t('projects.noPeople')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {project.people.map((person) => (
                                <li key={person.id}>
                                    <Link href={route('jarvis.people.show', person.id)} className="text-sky-300">
                                        {person.display_name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title={t('projects.organizations')}>
                    {(project?.organizations || []).length === 0 ? (
                        <p className="text-slate-500">{t('projects.noOrgs')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {project.organizations.map((organization) => (
                                <li key={organization.id}>
                                    <Link href={route('jarvis.organizations.show', organization.id)} className="text-sky-300">
                                        {organization.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title={t('projects.sources')}>
                    {(project?.groups || []).length === 0 && (project?.source_bindings || []).length === 0 ? (
                        <p className="text-slate-500">{t('projects.noSources')}</p>
                    ) : (
                        <ul className="space-y-1">
                            {(project.groups || []).map((group) => (
                                <li key={`g-${group.id}`}>{group.title}</li>
                            ))}
                            {(project.source_bindings || []).map((binding) => (
                                <li key={`s-${binding.id}`}>{binding.source_type}</li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title={t('projects.meetingsFuture')}>
                    <p className="text-slate-500">{t('projects.comingPhase', { phase: '5A' })}</p>
                </Section>
                <Section title={t('projects.commitmentsFuture')}>
                    {(commitments || []).length === 0 ? (
                        <p className="text-slate-500">{t('commitments.empty')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {commitments.map((item) => (
                                <li key={item.id}>
                                    <Link href={`/lavr/commitments/${item.id}`} className="text-sky-300">
                                        {item.title} · {item.status}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
                <Section title={t('projects.decisionsFuture')}>
                    <p className="text-slate-500">{t('projects.comingLater')}</p>
                </Section>

                {admin_href ? (
                    <a href={admin_href} className="mt-6 inline-block text-sm text-sky-300">
                        {t('projects.openFull')}
                    </a>
                ) : null}
            </div>
        </LavrAppShell>
    );
}

function Section({ title, children }) {
    return (
        <section className="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm leading-6 text-slate-200">
            <h2 className="text-xs uppercase tracking-[0.14em] text-slate-500">{title}</h2>
            <div className="mt-2">{children}</div>
        </section>
    );
}
