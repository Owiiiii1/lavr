import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Meetings() {
    const { t } = useTranslation();
    const { meetings = [], filters = {}, projects = [], organizations = [], people = [], defaultReviewPersonId = '', maxFileMb = 8, archived = false, activeCount = 0, archivedCount = 0 } = usePage().props;
    const pageTitle = archived ? t('meetings.archiveTitle') : t('meetings.title');
    const listRoute = archived ? 'jarvis.meetings.archived' : 'jarvis.meetings.index';
    const [showCreate, setShowCreate] = useState(false);
    const [showPlanned, setShowPlanned] = useState(false);
    const form = useForm({
        title: '',
        started_at: '',
        project_id: '',
        organization_id: '',
        transcript: null,
        pasted_text: '',
        review_subject_person_id: defaultReviewPersonId || '',
        skip_leadership_review: false,
    });
    const plannedForm = useForm({
        title: '',
        started_at: '',
        ended_at: '',
        location: '',
        project_id: '',
        organization_id: '',
    });

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route(listRoute),
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
            <Head title={pageTitle} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <div className="flex items-start justify-between gap-3">
                    <h1 className="text-2xl font-semibold text-white">{pageTitle}</h1>
                    <div className="flex flex-wrap justify-end gap-2">
                        <button type="button" onClick={() => setShowPlanned(true)} className="min-h-12 rounded-2xl border border-white/10 px-4 text-sm">
                            {t('meetings.planned')}
                        </button>
                        <button type="button" onClick={() => setShowCreate(true)} className="min-h-12 rounded-2xl bg-white/10 px-4 text-sm">
                            {t('meetings.import')}
                        </button>
                    </div>
                </div>
                <div className="mt-4 flex gap-2">
                    <button
                        type="button"
                        onClick={() => router.visit(route('jarvis.meetings.index'))}
                        className={`inline-flex min-h-12 items-center rounded-2xl px-4 text-sm ${archived ? 'border border-white/10' : 'bg-white text-slate-900'}`}
                    >
                        {t('meetings.active')}
                        <span className="ml-2 text-xs opacity-80">{activeCount}</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => router.visit(route('jarvis.meetings.archived'))}
                        className={`inline-flex min-h-12 items-center rounded-2xl px-4 text-sm ${archived ? 'bg-white text-slate-900' : 'border border-white/10'}`}
                    >
                        {t('meetings.archiveTab')}
                        <span className="ml-2 text-xs opacity-80">{archivedCount}</span>
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
                    <p className="mt-8 text-sm text-slate-400">{archived ? t('meetings.emptyArchive') : t('meetings.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {meetings.map((meeting) => (
                            <li key={meeting.id}>
                                <Link href={route('jarvis.meetings.show', meeting.id)} className="block min-h-16 rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                    <p className="text-xs text-slate-400">{meeting.started_at ? meeting.started_at.slice(0, 16).replace('T', ' ') : t('meetings.noDate')}</p>
                                    <p className="mt-1 text-sm font-medium text-white">{meeting.title}</p>
                                    <p className="mt-1 text-xs text-slate-400">
                                        {meeting.source_type === 'zoom' ? t('meetings.source_zoom') : t('meetings.source_manual')}
                                        {' · '}
                                        {meeting.project?.name || t('meetings.noProject')}
                                        {' · '}
                                        {t(`meetings.status_${meeting.analysis_status}`)}
                                    </p>
                                    {meeting.zoom_import?.status === 'failed' || meeting.zoom_import?.status === 'blocked_auth' || meeting.zoom_import?.status === 'transcript_unavailable' ? (
                                        <p className="mt-1 text-xs text-red-300">{t(`meetings.zoom_${meeting.zoom_import.status}`)}</p>
                                    ) : null}
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
                            <label className="block text-sm">
                                {t('meetings.review.who')}
                                <select value={form.data.review_subject_person_id} onChange={(event) => form.setData('review_subject_person_id', event.target.value)} className="mt-1 min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm">
                                    <option value="">{t('meetings.review.selectParticipant')}</option>
                                    {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                                </select>
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={Boolean(form.data.skip_leadership_review)} onChange={(event) => form.setData('skip_leadership_review', event.target.checked)} />
                                {t('meetings.review.skip')}
                            </label>
                            {form.errors.transcript ? <p className="text-xs text-red-400">{t(`meetings.error_${form.errors.transcript}`) === `meetings.error_${form.errors.transcript}` ? form.errors.transcript : t(`meetings.error_${form.errors.transcript}`)}</p> : null}
                            <div className="flex gap-2">
                                <button type="button" onClick={() => setShowCreate(false)} className="min-h-12 flex-1 rounded-2xl border border-white/10">{t('common.close')}</button>
                                <button type="submit" disabled={form.processing} className="min-h-12 flex-1 rounded-2xl bg-white/15 font-medium">{t('meetings.submit')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}

            {showPlanned ? (
                <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/50 px-4 pb-8 sm:items-center">
                    <div className="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-950 p-5 text-slate-100">
                        <h2 className="text-lg font-semibold">{t('meetings.planned')}</h2>
                        <p className="mt-1 text-xs text-slate-400">{t('meetings.plannedHint')}</p>
                        <form
                            className="mt-4 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                plannedForm.post(route('jarvis.meetings.planned.store'), { onSuccess: () => setShowPlanned(false) });
                            }}
                        >
                            <input value={plannedForm.data.title} onChange={(event) => plannedForm.setData('title', event.target.value)} placeholder={t('meetings.titleField')} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm" />
                            <label className="block text-xs text-slate-400">
                                {t('meetings.plannedStart')}
                                <input type="datetime-local" value={plannedForm.data.started_at} onChange={(event) => plannedForm.setData('started_at', event.target.value)} className="mt-1 min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-slate-100" />
                            </label>
                            <label className="block text-xs text-slate-400">
                                {t('meetings.plannedEnd')}
                                <input type="datetime-local" value={plannedForm.data.ended_at} onChange={(event) => plannedForm.setData('ended_at', event.target.value)} className="mt-1 min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-slate-100" />
                            </label>
                            <input value={plannedForm.data.location} onChange={(event) => plannedForm.setData('location', event.target.value)} placeholder={t('meetings.plannedLocation')} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm" />
                            <select value={plannedForm.data.project_id} onChange={(event) => plannedForm.setData('project_id', event.target.value)} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm">
                                <option value="">{t('meetings.noProject')}</option>
                                {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                            </select>
                            <select value={plannedForm.data.organization_id} onChange={(event) => plannedForm.setData('organization_id', event.target.value)} className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm">
                                <option value="">{t('meetings.noOrganization')}</option>
                                {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                            </select>
                            {plannedForm.errors.title ? <p className="text-xs text-red-400">{plannedForm.errors.title}</p> : null}
                            {plannedForm.errors.started_at ? <p className="text-xs text-red-400">{plannedForm.errors.started_at}</p> : null}
                            <div className="flex gap-2">
                                <button type="button" onClick={() => setShowPlanned(false)} className="min-h-12 flex-1 rounded-2xl border border-white/10">{t('common.close')}</button>
                                <button type="submit" disabled={plannedForm.processing} className="min-h-12 flex-1 rounded-2xl bg-white/15 font-medium">{t('meetings.plannedSubmit')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            ) : null}
        </LavrAppShell>
    );
}
