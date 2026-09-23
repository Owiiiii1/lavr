import MeetingReview from '@/Components/MeetingReview';
import AdminLayout from '@/Layouts/AdminLayout';
import { codeLabel, useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function MeetingShow() {
    const { t } = useTranslation();
    const label = (value) => codeLabel(t, 'admin.codes', value);
    const { meeting, projects = [], organizations = [], people = [], pollSeconds = 3 } = usePage().props;
    const [transcriptOpen, setTranscriptOpen] = useState(false);
    const [transcriptQuery, setTranscriptQuery] = useState('');
    const meta = useForm({
        title: meeting.title || '',
        started_at: meeting.started_at ? meeting.started_at.slice(0, 16) : '',
        project_id: meeting.project_id || '',
        organization_id: meeting.organization_id || '',
        notes: meeting.notes || '',
        source_external_id: meeting.source_external_id || '',
    });

    useEffect(() => {
        if (!['pending', 'processing'].includes(meeting.analysis_status)) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['meeting'] });
        }, Math.max(2, pollSeconds) * 1000);

        return () => window.clearInterval(timer);
    }, [meeting.analysis_status, meeting.id, pollSeconds]);

    const transcript = meeting.artifact?.normalized_text || meeting.artifact?.original_text || '';
    const filteredTranscript = useMemo(() => {
        if (!transcriptQuery.trim()) {
            return transcript;
        }

        return transcript
            .split('\n')
            .filter((line) => line.toLowerCase().includes(transcriptQuery.toLowerCase()))
            .join('\n');
    }, [transcript, transcriptQuery]);

    return (
        <AdminLayout title={meeting.title}>
            <Head title={meeting.title} />
            <div className="space-y-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Link href={meeting.status === 'archived' ? route('meetings.archived') : route('meetings.index')} className="text-sm text-indigo-700 hover:underline">
                        {meeting.status === 'archived' ? t('meetings.archiveTab') : t('meetings.back')}
                    </Link>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => router.post(route('meetings.rerun', meeting.id))} className="h-9 rounded-lg border px-3 text-sm">{t('meetings.rerun')}</button>
                        {meeting.status === 'archived' ? (
                            <Link href={route('meetings.restore', meeting.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">{t('admin.restore')}</Link>
                        ) : (
                            <Link href={route('meetings.archive', meeting.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">{t('admin.archive')}</Link>
                        )}
                    </div>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <p className="text-xs uppercase tracking-wider text-slate-500">{t('admin.meetingsAnalysis')}: {label(meeting.analysis_status)}</p>
                    <p className="mt-1 text-sm text-slate-600">{t('admin.source')}: {label(meeting.source_type)}</p>
                    {meeting.source_type === 'zoom' && meeting.zoom_import?.status ? (
                        <p className="mt-1 text-sm text-slate-600">
                            {t('admin.meetingsExternal')}: {label(meeting.zoom_import.status)}
                            {meeting.zoom_import.error ? ` · ${meeting.zoom_import.error}` : ''}
                        </p>
                    ) : null}
                    {meeting.zoom_import?.retryable ? (
                        <button type="button" onClick={() => router.post(route('meetings.zoom-retry', meeting.id))} className="mt-2 h-9 rounded-lg border px-3 text-sm">{t('meetings.zoomRetry')}</button>
                    ) : null}
                    {meeting.analysis?.error_message ? <p className="mt-1 text-sm text-red-600">{meeting.analysis.error_message}</p> : null}
                    <form
                        className="mt-3 grid gap-3 md:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            meta.patch(route('meetings.update', meeting.id));
                        }}
                    >
                        <label className="text-sm font-medium">{t('meetings.titleField')}<input value={meta.data.title} onChange={(event) => meta.setData('title', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <label className="text-sm font-medium">{t('admin.meetingsStarted')}<input type="datetime-local" value={meta.data.started_at} onChange={(event) => meta.setData('started_at', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <label className="text-sm font-medium">{t('admin.project')}
                            <select value={meta.data.project_id} onChange={(event) => meta.setData('project_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                                <option value="">{t('admin.none')}</option>
                                {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-medium">{t('admin.organization')}
                            <select value={meta.data.organization_id} onChange={(event) => meta.setData('organization_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                                <option value="">{t('admin.none')}</option>
                                {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-medium md:col-span-2">{t('admin.meetingsSourceId')}<input value={meta.data.source_external_id} onChange={(event) => meta.setData('source_external_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <label className="text-sm font-medium md:col-span-2">{t('admin.notes')}<textarea value={meta.data.notes} onChange={(event) => meta.setData('notes', event.target.value)} rows={3} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <button type="submit" className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">{t('admin.save')}</button>
                    </form>
                    <p className="mt-3 text-xs text-slate-500">{t('admin.meetingsSourceLine')} {label(meeting.source_type)} · {meeting.artifact?.original_filename || t('admin.meetingsNoFile')} · {meeting.artifact?.checksum_sha256?.slice(0, 12)}</p>
                    {meeting.artifact ? (
                        <a href={route('meetings.artifacts.download', [meeting.id, meeting.artifact.id])} className="mt-2 inline-block text-sm text-indigo-700">{t('admin.meetingsDownload')}</a>
                    ) : null}
                </section>

                <section id="participants" className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">{t('meetings.participants')}</h2>
                    <ul className="mt-2 space-y-3 text-sm">
                        {(meeting.participants || []).map((participant) => (
                            <li key={participant.id} className="rounded-lg border border-slate-100 p-3">
                                <p className="font-medium">{participant.display_name} {participant.resolved ? `→ ${participant.person_name}` : t('meetings.unresolved')}</p>
                                {participant.email ? <p className="text-xs text-slate-500">{participant.email}</p> : null}
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            router.post(route('meetings.participants.link', [meeting.id, participant.id]), {
                                                person_id: event.target.person_id.value,
                                            });
                                        }}
                                        className="flex gap-2"
                                    >
                                        <select name="person_id" className="rounded border px-2 py-1 text-sm" defaultValue={participant.person_id || ''}>
                                            <option value="">{t('meetings.linkPerson')}</option>
                                            {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                                        </select>
                                        <button type="submit" className="rounded border px-2 py-1 text-xs">{t('meetings.link')}</button>
                                    </form>
                                    {participant.resolved ? (
                                        <button type="button" className="rounded border px-2 py-1 text-xs" onClick={() => router.post(route('meetings.participants.unlink', [meeting.id, participant.id]))}>{t('meetings.unlink')}</button>
                                    ) : (
                                        <button type="button" className="rounded border px-2 py-1 text-xs" onClick={() => router.post(route('meetings.participants.create-person', [meeting.id, participant.id]))}>{t('meetings.createPerson')}</button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                <div id="meeting-actions">
                    <MeetingReview meeting={meeting} people={people} commitmentItems={meeting.commitment_items || []} routeName="meetings" />
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <button type="button" className="text-sm font-semibold" onClick={() => setTranscriptOpen((value) => !value)}>
                        {t('meetings.transcript')} {transcriptOpen ? '▾' : '▸'}
                    </button>
                    {transcriptOpen ? (
                        <div className="mt-3">
                            <input value={transcriptQuery} onChange={(event) => setTranscriptQuery(event.target.value)} placeholder={t('meetings.searchTranscript')} className="mb-2 w-full rounded-lg border px-3 py-2 text-sm" />
                            <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{filteredTranscript || '—'}</pre>
                        </div>
                    ) : null}
                </section>
            </div>
        </AdminLayout>
    );
}
