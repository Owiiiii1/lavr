import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, useForm } from '@inertiajs/react';

import LeadershipInsights from '@/personal-workspace/LeadershipInsights';

export default function ProjectShow({
    project,
    commitments = [],
    process = {},
    admin_href,
    available_google_accounts = [],
    available_telegram_groups = [],
}) {
    const { t } = useTranslation();
    const sourceForm = useForm({ source_type: 'google_mailbox', source_id: '' });
    const sourceOptions = sourceForm.data.source_type === 'telegram_group'
        ? available_telegram_groups
        : available_google_accounts;

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
                        <ul className="space-y-2">
                            {(project.groups || []).map((group) => (
                                <li key={`g-${group.id}`}>💬 {group.title}</li>
                            ))}
                            {(project.source_bindings || []).map((binding) => (
                                <li key={`s-${binding.id}`} className="flex items-start justify-between gap-3">
                                    <span>
                                        {binding.label || binding.source_type}
                                        {binding.address ? ` · ${binding.address}` : ''}
                                        {binding.binding_kind === 'suggested' ? ` · ${t('projects.suggested')}` : ''}
                                    </span>
                                    <Link
                                        href={route('projects.sources.destroy', [project.id, binding.id])}
                                        method="delete"
                                        as="button"
                                        className="shrink-0 text-xs text-slate-400"
                                    >
                                        {t('projects.detachSource')}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    {(available_google_accounts.length > 0 || available_telegram_groups.length > 0) && (
                        <form
                            className="mt-3 flex flex-wrap gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                sourceForm.post(route('projects.sources.store', project.id), {
                                    preserveScroll: true,
                                    onSuccess: () => sourceForm.reset('source_id'),
                                });
                            }}
                        >
                            <select
                                value={sourceForm.data.source_type}
                                onChange={(event) => sourceForm.setData('source_type', event.target.value)}
                                className="rounded-lg border border-white/10 bg-white/5 px-2 py-1.5 text-xs text-slate-100"
                            >
                                <option value="google_mailbox">{t('projects.mailbox')}</option>
                                <option value="google_calendar">{t('projects.calendar')}</option>
                                <option value="telegram_group">{t('projects.telegramGroup')}</option>
                            </select>
                            <select
                                value={sourceForm.data.source_id}
                                onChange={(event) => sourceForm.setData('source_id', event.target.value)}
                                className="min-w-40 flex-1 rounded-lg border border-white/10 bg-white/5 px-2 py-1.5 text-xs text-slate-100"
                            >
                                <option value="">Select…</option>
                                {sourceOptions.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.label || item.title || item.email}
                                    </option>
                                ))}
                            </select>
                            <button
                                type="submit"
                                disabled={sourceForm.processing || !sourceForm.data.source_id}
                                className="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-slate-200 disabled:opacity-50"
                            >
                                {t('projects.attachSource')}
                            </button>
                        </form>
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
                <LeadershipInsights
                    title={t('leadership.process')}
                    metrics={process.metrics || {}}
                    findings={process.findings || []}
                    emptyLabel={t('leadership.noPatterns')}
                />
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
