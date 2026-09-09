import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Meetings() {
    const { t } = useTranslation();
    const { meetings = [], filters = {}, projects = [], organizations = [], maxFileMb = 8 } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const form = useForm({
        title: '',
        started_at: '',
        project_id: '',
        organization_id: '',
        transcript: null,
        pasted_text: '',
    });

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('jarvis.meetings.index'),
            {
                q: event.target.q.value,
                project_id: event.target.project_id.value,
                from: event.target.from.value,
                analysis_status: event.target.analysis_status.value,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <LavrAppShell>
            <Head title={t('meetings.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <div className="flex items-start justify-between gap-3">
                    <h1 className="text-2xl font-semibold text-white">{t('meetings.title')}</h1>
                    <button type="button" onClick={() => setShowCreate(true)} className="min-h-12 rounded-2xl bg-white/10 px-4 text-sm">
                        {t('meetings.import')}
                    </button>
                </div>
                <form className="mt-4 grid gap-2" onSubmit={applyFilters}>
                    <input name="q" defaultValue={filters.q || ''} placeholder={t('meetings.searchPlaceholder')} className="min-h-12 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white" />
                    <select name="project_id" defaultValue={filters.project_id || ''} className="min-h-12 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white">
                        <option value="">{t('meetings.allProjects')}</option>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>{project.name}</option>
                        ))}
                    </select>
                    <input type="date" name="from" defaultValue={filters.from || ''} className="min-h-12 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white" />
                    <div className="flex gap-2">
                        <select name="analysis_status" defaultValue={filters.analysis_status || ''} className="min-h-12 flex-1 rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white">
                            <option value="">{t('meetings.allStatuses')}</option>
                            <option value="pending">{t('meetings.status_pending')}</option>
                            <option value="processing">{t('meetings.status_processing')}</option>
                            <option value="completed">{t('meetings.status_completed')}</option>
                            <option value="failed">{t('meetings.status_failed')}</option>
                        </select>
                        <button type="submit" className="min-h-12 rounded-2xl border border-white/10 px-4 text-sm">{t('meetings.filter')}</button>
                    </div>
                </form>

                {meetings.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">{t('meetings.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {meetings.map((meeting) => (
                            <li key={meeting.id}>
                                <Link href={route('jarvis.meetings.show', meeting.id)} className="block min-h-16 rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                    <p className="text-xs text-slate-400">{meeting.started_at ? meeting.started_at.slice(0, 16).replace('T', ' ') : t('meetings.noDate')}</p>
                                    <p className="mt-1 text-sm font-medium text-white">{meeting.title}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {meeting.project?.name || t('meetings.noProject')}
                                        {' · '}
                                        {t(`meetings.status_${meeting.analysis_status}`)}
                                    </p>
                                    {meeting.summary ? <p className="mt-2 line-clamp-2 text-xs text-slate-300">{meeting.summary}</p> : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {showCreate ? (
                <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 px-4 pb-8 sm:items-center">
                    <div className="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-950 p-5 text-slate-100">
                        <h2 className="text-lg font-semibold">{t('meetings.import')}</h2>
                        <form
                            className="mt-4 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(route('jarvis.meetings.store'), { forceFormData: true, onSuccess: () => setShowCreate(false) });
                            }}
                        >
                            <input value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} placeholder={t('meetings.titleField')} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm" />
                            <input type="datetime-local" value={form.data.started_at} onChange={(event) => form.setData('started_at', event.target.value)} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm" />
                            <select value={form.data.project_id} onChange={(event) => form.setData('project_id', event.target.value)} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm">
                                <option value="">{t('meetings.noProject')}</option>
                                {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                            </select>
                            <select value={form.data.organization_id} onChange={(event) => form.setData('organization_id', event.target.value)} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm">
                                <option value="">{t('meetings.noOrganization')}</option>
                                {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                            </select>
                            <p className="text-xs text-slate-400">{t('meetings.fileHint', { size: maxFileMb })}</p>
                            <input type="file" accept=".txt,.vtt,.srt,.md,text/plain" onChange={(event) => form.setData('transcript', event.target.files?.[0] ?? null)} className="w-full text-sm" />
                            <textarea value={form.data.pasted_text} onChange={(event) => form.setData('pasted_text', event.target.value)} rows={6} placeholder={t('meetings.pastePlaceholder')} className="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm" />
                            {form.errors.transcript ? <p className="text-xs text-red-400">{t(`meetings.error_${form.errors.transcript}`) === `meetings.error_${form.errors.transcript}` ? form.errors.transcript : t(`meetings.error_${form.errors.transcript}`)}</p> : null}
                            <div className="flex gap-2">
                                <button type="button" onClick={() => setShowCreate(false)} className="min-h-12 flex-1 rounded-2xl border border-white/10">{t('common.close')}</button>
                                <button type="submit" disabled={form.processing} className="min-h-12 flex-1 rounded-2xl bg-white/15 font-medium">{t('meetings.submit')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}
        </LavrAppShell>
    );
}
