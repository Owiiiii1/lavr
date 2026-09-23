import AdminLayout from '@/Layouts/AdminLayout';
import { codeLabel, useTranslation } from '@/locales/useTranslation';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

export default function MeetingsIndex() {
    const { t } = useTranslation();
    const label = (value) => codeLabel(t, 'admin.codes', value);
    const { meetings = [], filters = {}, projects = [], organizations = [], people = [], defaultReviewPersonId = '', maxFileMb = 8, archived = false, activeCount = 0, archivedCount = 0 } = usePage().props;
    const pageTitle = archived ? t('meetings.archiveTitle') : t('meetings.title');
    const listRoute = archived ? 'meetings.archived' : 'meetings.index';
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
        notes: '',
    });

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route(listRoute),
            {
                q: event.target.q.value,
                project_id: event.target.project_id.value,
                from: event.target.from.value,
                to: event.target.to.value,
                analysis_status: event.target.analysis_status.value,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title={pageTitle}>
            <Head title={pageTitle} />
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={() => router.visit(route('meetings.index'))}
                        className={`inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium ${
                            archived
                                ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                : 'bg-[#0B1220] text-white'
                        }`}
                    >
                        {t('meetings.active')}
                        <span className="ml-2 text-xs opacity-80">{activeCount}</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => router.visit(route('meetings.archived'))}
                        className={`inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium ${
                            archived
                                ? 'bg-[#0B1220] text-white'
                                : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        {t('meetings.archiveTab')}
                        <span className="ml-2 text-xs opacity-80">{archivedCount}</span>
                    </button>
                </div>
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <p className="text-sm text-slate-600">
                            {archived ? t('meetings.archiveTitle') : t('admin.meetingsIntro')}
                        </p>
                        {archived ? null : (
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => setShowPlanned(true)}
                                className="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                <Plus className="h-4 w-4" />
                                {t('meetings.planned')}
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowCreate(true)}
                                className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700"
                            >
                                <Plus className="h-4 w-4" />
                                {t('admin.meetingsNew')}
                            </button>
                        </div>
                        )}
                    </div>
                    <form className="mt-4 grid gap-2 md:grid-cols-5" onSubmit={applyFilters}>
                        <input name="q" defaultValue={filters.q || ''} placeholder={t('admin.search')} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <select name="project_id" defaultValue={filters.project_id || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">{t('meetings.allProjects')}</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                        <input type="date" name="from" defaultValue={filters.from || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <input type="date" name="to" defaultValue={filters.to || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <div className="flex gap-2">
                            <select name="analysis_status" defaultValue={filters.analysis_status || ''} className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option value="">{t('meetings.allStatuses')}</option>
                                <option value="pending">{label('pending')}</option>
                                <option value="processing">{label('processing')}</option>
                                <option value="completed">{label('completed')}</option>
                                <option value="failed">{label('failed')}</option>
                            </select>
                            <button type="submit" className="rounded-lg border border-slate-300 px-3 text-sm">
                                {t('admin.filter')}
                            </button>
                        </div>
                    </form>
                </section>

                <section className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold">{t('admin.meetingsColTitle')}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t('admin.project')}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t('admin.date')}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t('admin.meetingsColParticipants')}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t('admin.source')}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t('admin.meetingsAnalysis')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {meetings.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                                        {archived ? t('meetings.emptyArchive') : t('meetings.empty')}
                                    </td>
                                </tr>
                            ) : (
                                meetings.map((meeting) => (
                                    <tr
                                        key={meeting.id}
                                        className="cursor-pointer transition hover:bg-amber-50/60"
                                        tabIndex={0}
                                        onClick={() => router.visit(route('meetings.show', meeting.id))}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                router.visit(route('meetings.show', meeting.id));
                                            }
                                        }}
                                    >
                                        <td className="px-4 py-3 font-medium text-slate-900">{meeting.title}</td>
                                        <td className="px-4 py-3">{meeting.project?.name || '—'}</td>
                                        <td className="px-4 py-3">{meeting.started_at ? meeting.started_at.slice(0, 16).replace('T', ' ') : '—'}</td>
                                        <td className="px-4 py-3">{meeting.participants_count ?? 0}</td>
                                        <td className="px-4 py-3">{label(meeting.source_type)}</td>
                                        <td className="px-4 py-3">{label(meeting.analysis_status)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </section>
            </div>

            {showCreate && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
                    <div className="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
                        <div className="flex items-start justify-between">
                            <h2 className="text-base font-semibold text-slate-900">{t('admin.meetingsImport')}</h2>
                            <button type="button" onClick={() => setShowCreate(false)} className="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100">
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <form
                            className="mt-4 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(route('meetings.store'), { forceFormData: true, onSuccess: () => setShowCreate(false) });
                            }}
                        >
                            <label className="block text-sm font-medium">
                                {t('meetings.titleField')}
                                <input value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.meetingsDate')}
                                <input type="datetime-local" value={form.data.started_at} onChange={(event) => form.setData('started_at', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.project')}
                                <select value={form.data.project_id} onChange={(event) => form.setData('project_id', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{t('admin.none')}</option>
                                    {projects.map((project) => (
                                        <option key={project.id} value={project.id}>{project.name}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.organization')}
                                <select value={form.data.organization_id} onChange={(event) => form.setData('organization_id', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{t('admin.none')}</option>
                                    {organizations.map((organization) => (
                                        <option key={organization.id} value={organization.id}>{organization.name}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.meetingsFile', { size: maxFileMb })}
                                <input type="file" accept=".txt,.vtt,.srt,.md,text/plain" onChange={(event) => form.setData('transcript', event.target.files?.[0] ?? null)} className="mt-1 w-full text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.meetingsPaste')}
                                <textarea value={form.data.pasted_text} onChange={(event) => form.setData('pasted_text', event.target.value)} rows={6} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                {t('meetings.review.who')}
                                <select value={form.data.review_subject_person_id} onChange={(event) => form.setData('review_subject_person_id', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{t('meetings.review.selectParticipant')}</option>
                                    {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                                </select>
                            </label>
                            <p className="text-xs text-slate-500">{t('meetings.review.defaultHint')}</p>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={Boolean(form.data.skip_leadership_review)} onChange={(event) => form.setData('skip_leadership_review', event.target.checked)} />
                                {t('meetings.review.skip')}
                            </label>
                            {form.errors.transcript && <p className="text-xs text-red-600">{form.errors.transcript}</p>}
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setShowCreate(false)} className="h-10 rounded-lg border px-4 text-sm">{t('admin.cancel')}</button>
                                <button type="submit" disabled={form.processing} className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">{t('admin.meetingsImportAction')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {showPlanned && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
                    <div className="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
                        <div className="flex items-start justify-between">
                            <h2 className="text-base font-semibold text-slate-900">{t('meetings.planned')}</h2>
                            <button type="button" onClick={() => setShowPlanned(false)} className="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100">
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <p className="mt-1 text-xs text-slate-500">{t('meetings.plannedHint')}</p>
                        <form
                            className="mt-4 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                plannedForm.post(route('meetings.planned.store'), { onSuccess: () => setShowPlanned(false) });
                            }}
                        >
                            <label className="block text-sm font-medium">
                                {t('meetings.titleField')}
                                <input value={plannedForm.data.title} onChange={(event) => plannedForm.setData('title', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="block text-sm font-medium">
                                    {t('meetings.plannedStart')}
                                    <input type="datetime-local" value={plannedForm.data.started_at} onChange={(event) => plannedForm.setData('started_at', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                                </label>
                                <label className="block text-sm font-medium">
                                    {t('meetings.plannedEnd')}
                                    <input type="datetime-local" value={plannedForm.data.ended_at} onChange={(event) => plannedForm.setData('ended_at', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                                </label>
                            </div>
                            <label className="block text-sm font-medium">
                                {t('meetings.plannedLocation')}
                                <input value={plannedForm.data.location} onChange={(event) => plannedForm.setData('location', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.project')}
                                <select value={plannedForm.data.project_id} onChange={(event) => plannedForm.setData('project_id', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{t('admin.none')}</option>
                                    {projects.map((project) => (
                                        <option key={project.id} value={project.id}>{project.name}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.organization')}
                                <select value={plannedForm.data.organization_id} onChange={(event) => plannedForm.setData('organization_id', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">{t('admin.none')}</option>
                                    {organizations.map((organization) => (
                                        <option key={organization.id} value={organization.id}>{organization.name}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium">
                                {t('admin.notes')}
                                <textarea value={plannedForm.data.notes} onChange={(event) => plannedForm.setData('notes', event.target.value)} rows={3} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            {plannedForm.errors.title && <p className="text-xs text-red-600">{plannedForm.errors.title}</p>}
                            {plannedForm.errors.started_at && <p className="text-xs text-red-600">{plannedForm.errors.started_at}</p>}
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setShowPlanned(false)} className="h-10 rounded-lg border px-4 text-sm">{t('admin.cancel')}</button>
                                <button type="submit" disabled={plannedForm.processing} className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">{t('admin.create')}</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
