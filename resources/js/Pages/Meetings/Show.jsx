import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

function Items({ title, items = [], render }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            {items.length === 0 ? <p className="mt-2 text-sm text-slate-400">None</p> : <ul className="mt-2 space-y-2 text-sm">{items.map(render)}</ul>}
        </section>
    );
}

export default function MeetingShow() {
    const { meeting, projects = [], organizations = [], people = [], pollSeconds = 3 } = usePage().props;
    const result = meeting.analysis?.result || {};
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
                    <Link href={route('meetings.index')} className="text-sm text-indigo-700 hover:underline">Back to meetings</Link>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => router.post(route('meetings.rerun', meeting.id))} className="h-9 rounded-lg border px-3 text-sm">Re-run analysis</button>
                        {meeting.status === 'archived' ? (
                            <Link href={route('meetings.restore', meeting.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">Restore</Link>
                        ) : (
                            <Link href={route('meetings.archive', meeting.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">Archive</Link>
                        )}
                    </div>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <p className="text-xs uppercase tracking-wider text-slate-500">Analysis: {meeting.analysis_status}</p>
                    <p className="mt-1 text-sm text-slate-600">Source: {meeting.source_type === 'zoom' ? 'Zoom' : meeting.source_type}</p>
                    {meeting.source_type === 'zoom' && meeting.zoom_import?.status ? (
                        <p className="mt-1 text-sm text-slate-600">
                            External source: {meeting.zoom_import.status}
                            {meeting.zoom_import.error ? ` · ${meeting.zoom_import.error}` : ''}
                        </p>
                    ) : null}
                    {meeting.zoom_import?.retryable ? (
                        <button type="button" onClick={() => router.post(route('meetings.zoom-retry', meeting.id))} className="mt-2 h-9 rounded-lg border px-3 text-sm">Retry Zoom import</button>
                    ) : null}
                    {meeting.analysis?.error_message ? <p className="mt-1 text-sm text-red-600">{meeting.analysis.error_message}</p> : null}
                    <form
                        className="mt-3 grid gap-3 md:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            meta.patch(route('meetings.update', meeting.id));
                        }}
                    >
                        <label className="text-sm font-medium">Title<input value={meta.data.title} onChange={(event) => meta.setData('title', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <label className="text-sm font-medium">Started<input type="datetime-local" value={meta.data.started_at} onChange={(event) => meta.setData('started_at', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <label className="text-sm font-medium">Project
                            <select value={meta.data.project_id} onChange={(event) => meta.setData('project_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                                <option value="">None</option>
                                {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-medium">Organization
                            <select value={meta.data.organization_id} onChange={(event) => meta.setData('organization_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm">
                                <option value="">None</option>
                                {organizations.map((organization) => <option key={organization.id} value={organization.id}>{organization.name}</option>)}
                            </select>
                        </label>
                        <label className="text-sm font-medium md:col-span-2">Calendar / source id<input value={meta.data.source_external_id} onChange={(event) => meta.setData('source_external_id', event.target.value)} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <label className="text-sm font-medium md:col-span-2">Notes<textarea value={meta.data.notes} onChange={(event) => meta.setData('notes', event.target.value)} rows={3} className="mt-1 w-full rounded-lg border px-3 py-2 text-sm" /></label>
                        <button type="submit" className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">Save</button>
                    </form>
                    <p className="mt-3 text-xs text-slate-500">Source {meeting.source_type} · {meeting.artifact?.original_filename || 'no file'} · {meeting.artifact?.checksum_sha256?.slice(0, 12)}</p>
                    {meeting.artifact ? (
                        <a href={route('meetings.artifacts.download', [meeting.id, meeting.artifact.id])} className="mt-2 inline-block text-sm text-indigo-700">Download original</a>
                    ) : null}
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Participants</h2>
                    <ul className="mt-2 space-y-3 text-sm">
                        {(meeting.participants || []).map((participant) => (
                            <li key={participant.id} className="rounded-lg border border-slate-100 p-3">
                                <p className="font-medium">{participant.display_name} {participant.resolved ? `→ ${participant.person_name}` : '(unresolved)'}</p>
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
                                            <option value="">Link person</option>
                                            {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                                        </select>
                                        <button type="submit" className="rounded border px-2 py-1 text-xs">Link</button>
                                    </form>
                                    {participant.resolved ? (
                                        <button type="button" className="rounded border px-2 py-1 text-xs" onClick={() => router.post(route('meetings.participants.unlink', [meeting.id, participant.id]))}>Unlink</button>
                                    ) : (
                                        <button type="button" className="rounded border px-2 py-1 text-xs" onClick={() => router.post(route('meetings.participants.create-person', [meeting.id, participant.id]))}>Create Person</button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Summary</h2>
                    <p className="mt-2 text-sm text-slate-700">{result.summary?.executive || meeting.summary || '—'}</p>
                    <ul className="mt-2 list-disc pl-5 text-sm">
                        {(result.summary?.outcomes || []).map((item) => <li key={item}>{item}</li>)}
                    </ul>
                </section>

                <Items title="Decisions" items={result.decisions || []} render={(item) => <li key={item.text}><p>{item.text}</p><Evidence evidence={item.evidence} /></li>} />
                <Items title="Action items" items={result.action_items || []} render={(item) => <li key={item.task}><p>{item.owner ? `${item.owner}: ` : ''}{item.task}</p><Evidence evidence={item.evidence} /></li>} />
                <Items title="Commitments detected" items={meeting.commitment_items || result.commitments_detected || []} render={(item) => (
                    <li key={item.index ?? item.action}>
                        <p>{item.person_name || item.person_ref || '—'}: {item.action}</p>
                        {item.promoted && item.commitment_id ? (
                            <Link href={route('commitments.show', item.commitment_id)} className="text-xs text-indigo-700">Open commitment</Link>
                        ) : (
                            <button type="button" className="mt-1 h-8 rounded border px-2 text-xs" onClick={() => router.post(route('meetings.commitments.promote', meeting.id), { index: item.index })}>Promote</button>
                        )}
                        <Evidence evidence={item.evidence} />
                    </li>
                )} />
                <Items title="Deadlines" items={result.deadlines || []} render={(item) => <li key={item.text}><p>{item.text} {item.deadline_at || item.deadline_raw || ''}</p><Evidence evidence={item.evidence} /></li>} />
                <Items title="Open questions" items={result.open_questions || []} render={(item) => <li key={item.text}>{item.text}</li>} />
                <Items title="Risks" items={result.risks || []} render={(item) => <li key={item.text}>{item.text}</li>} />
                <Items title="Follow-ups" items={result.follow_ups || []} render={(item) => <li key={item.text}>{item.text}</li>} />

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <button type="button" className="text-sm font-semibold" onClick={() => setTranscriptOpen((value) => !value)}>
                        Transcript {transcriptOpen ? '▾' : '▸'}
                    </button>
                    {transcriptOpen ? (
                        <div className="mt-3">
                            <input value={transcriptQuery} onChange={(event) => setTranscriptQuery(event.target.value)} placeholder="Search transcript" className="mb-2 w-full rounded-lg border px-3 py-2 text-sm" />
                            <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{filteredTranscript || '—'}</pre>
                        </div>
                    ) : null}
                </section>
            </div>
        </AdminLayout>
    );
}

function Evidence({ evidence }) {
    if (!evidence?.excerpt) {
        return null;
    }

    return <p className="mt-1 text-xs text-slate-500">“{evidence.excerpt}” {evidence.speaker ? `— ${evidence.speaker}` : ''} {evidence.timestamp || ''}</p>;
}
