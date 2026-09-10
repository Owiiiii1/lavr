import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

export default function CommitmentShow() {
    const { commitment, people = [], projects = [], mergeCandidates = [] } = usePage().props;
    const form = useForm({
        title: commitment.title || '',
        expected_result: commitment.expected_result || '',
        person_id: commitment.person?.id || '',
        project_id: commitment.project?.id || '',
        deadline_at: commitment.deadline_at ? commitment.deadline_at.slice(0, 16) : '',
        deadline_raw: commitment.deadline_raw || '',
        notes: '',
    });
    const evidenceForm = useForm({ evidence_type: 'completion', excerpt: '' });
    const mergeForm = useForm({ duplicate_id: '' });

    return (
        <AdminLayout title={commitment.title}>
            <Head title={commitment.title} />
            <div className="space-y-4">
                <Link href={route('commitments.index')} className="text-sm text-indigo-700 hover:underline">Back to commitments</Link>
                <p className="text-xs uppercase tracking-wider text-slate-500">{commitment.status} · {commitment.source_type} · {commitment.confidence}</p>
                {commitment.unresolved_person ? <p className="text-sm text-amber-700">Unresolved person</p> : null}

                <form className="grid gap-2 rounded-xl border bg-white p-4 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); form.patch(route('commitments.update', commitment.id)); }}>
                    <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} className="rounded-lg border px-3 py-2 text-sm" />
                    <input value={form.data.expected_result} onChange={(e) => form.setData('expected_result', e.target.value)} placeholder="Expected result" className="rounded-lg border px-3 py-2 text-sm" />
                    <select value={form.data.person_id} onChange={(e) => form.setData('person_id', e.target.value)} className="rounded-lg border px-3 py-2 text-sm">
                        <option value="">Person</option>
                        {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                    </select>
                    <select value={form.data.project_id} onChange={(e) => form.setData('project_id', e.target.value)} className="rounded-lg border px-3 py-2 text-sm">
                        <option value="">Project</option>
                        {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                    </select>
                    <input type="datetime-local" value={form.data.deadline_at} onChange={(e) => form.setData('deadline_at', e.target.value)} className="rounded-lg border px-3 py-2 text-sm" />
                    <input value={form.data.deadline_raw} onChange={(e) => form.setData('deadline_raw', e.target.value)} placeholder="Deadline raw" className="rounded-lg border px-3 py-2 text-sm" />
                    <button type="submit" className="h-10 rounded-lg bg-indigo-600 px-4 text-sm text-white">Save</button>
                </form>

                <div className="flex flex-wrap gap-2">
                    {commitment.lifecycle_status === 'detected' ? (
                        <>
                            <button type="button" className="h-9 rounded-lg border px-3 text-sm" onClick={() => router.post(route('commitments.confirm', commitment.id), form.data)}>Confirm</button>
                            <button type="button" className="h-9 rounded-lg border px-3 text-sm" onClick={() => router.post(route('commitments.dismiss', commitment.id))}>Dismiss</button>
                        </>
                    ) : null}
                    {['open', 'due_soon', 'overdue', 'likely_done'].includes(commitment.status) ? (
                        <>
                            <button type="button" className="h-9 rounded-lg border px-3 text-sm" onClick={() => router.post(route('commitments.likely-done', commitment.id), { note: evidenceForm.data.excerpt })}>Likely done</button>
                            <button type="button" className="h-9 rounded-lg border px-3 text-sm" onClick={() => router.post(route('commitments.complete', commitment.id), { note: form.data.notes })}>Mark confirmed</button>
                            <button type="button" className="h-9 rounded-lg border px-3 text-sm" onClick={() => router.post(route('commitments.cancel', commitment.id))}>Cancel</button>
                        </>
                    ) : null}
                </div>

                <section className="rounded-xl border bg-white p-4">
                    <h2 className="text-sm font-semibold">Evidence</h2>
                    <ul className="mt-2 space-y-2 text-sm">
                        {(commitment.evidence || []).map((row) => (
                            <li key={row.id}>{row.evidence_type}: {row.excerpt || '—'} <span className="text-slate-400">{row.observed_at || ''}</span></li>
                        ))}
                    </ul>
                    <form className="mt-3 flex gap-2" onSubmit={(event) => { event.preventDefault(); evidenceForm.post(route('commitments.evidence', commitment.id)); }}>
                        <select value={evidenceForm.data.evidence_type} onChange={(e) => evidenceForm.setData('evidence_type', e.target.value)} className="rounded-lg border px-2 text-sm">
                            {['promise', 'deadline', 'progress', 'delivery', 'completion', 'confirmation', 'cancellation', 'other'].map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                        <input value={evidenceForm.data.excerpt} onChange={(e) => evidenceForm.setData('excerpt', e.target.value)} placeholder="Short excerpt" className="flex-1 rounded-lg border px-3 py-2 text-sm" />
                        <button type="submit" className="rounded-lg border px-3 text-sm">Add</button>
                    </form>
                </section>

                <section className="rounded-xl border bg-white p-4">
                    <h2 className="text-sm font-semibold">Source</h2>
                    {commitment.meeting ? <Link href={route('meetings.show', commitment.meeting.id)} className="text-sm text-indigo-700">{commitment.meeting.title}</Link> : <p className="text-sm text-slate-500">{commitment.source_type}</p>}
                </section>

                <section className="rounded-xl border bg-white p-4">
                    <h2 className="text-sm font-semibold">History</h2>
                    <ul className="mt-2 space-y-1 text-sm text-slate-600">
                        {(commitment.history || []).map((row) => (
                            <li key={row.id}>{row.from_status || '—'} → {row.to_status} ({row.reason})</li>
                        ))}
                    </ul>
                </section>

                <form className="flex gap-2 rounded-xl border bg-white p-4" onSubmit={(event) => { event.preventDefault(); mergeForm.post(route('commitments.merge', commitment.id)); }}>
                    <select value={mergeForm.data.duplicate_id} onChange={(e) => mergeForm.setData('duplicate_id', e.target.value)} className="rounded-lg border px-3 py-2 text-sm">
                        <option value="">Merge duplicate into this</option>
                        {mergeCandidates.map((row) => <option key={row.id} value={row.id}>#{row.id} {row.title}</option>)}
                    </select>
                    <button type="submit" className="rounded-lg border px-3 text-sm">Merge</button>
                </form>
            </div>
        </AdminLayout>
    );
}
