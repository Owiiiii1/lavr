import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';
import LeadershipInsights from '@/personal-workspace/LeadershipInsights';

export default function PersonShow({ person, commitments = [], operational = {} }) {
    const { t } = useTranslation();

    return (
        <LavrAppShell>
            <Head title={person?.display_name || t('people.card')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/people" className="text-sm text-sky-300">
                    {t('people.back')}
                </Link>
                <h1 className="mt-3 text-2xl font-semibold text-white">{person?.display_name}</h1>
                <p className="mt-2 text-xs uppercase tracking-[0.14em] text-slate-500">{person?.status}</p>
                <p className="mt-3 text-sm text-slate-300">{(person?.roles || []).join(', ') || t('people.noRoles')}</p>
                {person?.position ? (
                    <p className="mt-2 text-sm text-slate-200">
                        {person.position}
                        {person.department ? ` · ${person.department}` : ''}
                    </p>
                ) : null}

                <Section title={t('people.contacts')}>
                    <p>{t('people.email')}: {person?.primary_email || '—'}</p>
                    <p>{t('people.phone')}: {person?.primary_phone || '—'}</p>
                    <p>{t('people.telegram')}: {person?.telegram_username || '—'}</p>
                </Section>

                <Section title={t('people.organizations')}>
                    {(person?.organizations || []).length === 0 ? (
                        <p className="text-slate-500">{t('people.noOrganizations')}</p>
                    ) : (
                        <ul className="space-y-1">
                            {person.organizations.map((organization) => (
                                <li key={organization.id}>
                                    <Link href={route('jarvis.organizations.show', organization.id)} className="text-sky-300">
                                        {organization.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title={t('people.projects')}>
                    {(person?.projects || []).length === 0 ? (
                        <p className="text-slate-500">{t('people.noProjects')}</p>
                    ) : (
                        <ul className="flex flex-wrap gap-2">
                            {person.projects.map((project) => (
                                <li key={project.id}>
                                    <Link
                                        href={route('jarvis.workspace.projects.show', project.id)}
                                        className="inline-flex rounded-full bg-white/10 px-3 py-1 text-xs text-white"
                                    >
                                        {project.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title={t('people.responsibilities')}>
                    {(person?.responsibilities || []).length === 0 ? (
                        <p className="text-slate-500">—</p>
                    ) : (
                        <ul className="list-disc pl-5">
                            {person.responsibilities.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    )}
                </Section>

                {person?.notes ? (
                    <Section title={t('people.notes')}>
                        <p className="whitespace-pre-wrap">{person.notes}</p>
                    </Section>
                ) : null}

                <Section title={t('people.meetings')}>
                    <p className="text-slate-500">{t('people.phasePlaceholder', { phase: '5A' })}</p>
                </Section>
                <Section title={t('people.commitments')}>
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
                <LeadershipInsights
                    title={t('leadership.operational')}
                    metrics={operational.metrics || {}}
                    findings={operational.findings || []}
                    emptyLabel={t('leadership.noPatterns')}
                />
                <Section title={t('people.activity')}>
                    <p className="text-slate-500">{t('people.comingLater')}</p>
                </Section>
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
