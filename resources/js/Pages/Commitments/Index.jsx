import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

export default function CommitmentsIndex() {
    const { commitments = [], filters = {}, people = [], projects = [] } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const form = useForm({
        title: '',
        expected_result: '',
        person_id: '',
        project_id: '',
        deadline_at: '',
        notes: '',
    });

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('commitments.index'),
            {
                q: event.target.q.value,
                status: event.target.status.value,
                person_id: event.target.person_id.value,
                project_id: event.target.project_id.value,
                source: event.target.source.value,
                overdue: event.target.overdue.checked ? 1 : '',
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title="Commitments">
            <Head title="Commitments" />
            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <p className="text-sm text-slate-600">First-class promises. Not Tasks. Evidence stays attached to the operational record.</p>
                        <button type="button" onClick={() => setShowCreate(true)} className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">
                            <Plus className="h-4 w-4" />
                            New commitment
                        </button>
                    </div>
                    <form className="mt-4 grid gap-2 md:grid-cols-6" onSubmit={applyFilters}>
                        <input name="q" defaultValue={filters.q || ''} placeholder="Search" className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <select name="status" defaultValue={filters.status || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All statuses</option>
                            {['detected', 'open', 'due_soon', 'overdue', 'likely_done', 'confirmed', 'cancelled', 'discarded'].map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </select>
                        <select name="person_id" defaultValue={filters.person_id || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All people</option>
                            {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                        </select>
                        <select name="project_id" defaultValue={filters.project_id || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All projects</option>
                            {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                        </select>
                        <select name="source" defaultValue={filters.source || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All sources</option>
                            {['meeting', 'email', 'telegram', 'manual', 'knowledge_legacy', 'other'].map((source) => (
                                <option key={source} value={source}>{source}</option>
                            ))}
                        </select>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="overdue" defaultChecked={!!filters.overdue} />
                            Overdue
                        </label>
                        <button type="submit" className="rounded-lg border border-slate-300 px-3 text-sm">Filter</button>
                    </form>
                </section>

                {showCreate ? (
                    <form
                        className="grid gap-2 rounded-xl border bg-white p-4 md:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(route('commitments.store'), { onSuccess: () => setShowCreate(false) });
                        }}
                    >
                        <input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Action" className="rounded-lg border px-3 py-2 text-sm" />
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
                        <input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Notes" className="rounded-lg border px-3 py-2 text-sm" />
                        <div className="flex gap-2">
                            <button type="submit" className="h-10 rounded-lg bg-indigo-600 px-4 text-sm text-white">Create</button>
                            <button type="button" onClick={() => setShowCreate(false)} className="h-10 rounded-lg border px-4 text-sm">Cancel</button>
                        </div>
                    </form>
                ) : null}

                <section className="overflow-x-auto rounded-xl border bg-white">
                    <table className="min-w-full divide-y text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th className="px-3 py-2 text-left">Person</th>
                                <th className="px-3 py-2 text-left">Action</th>
                                <th className="px-3 py-2 text-left">Project</th>
                                <th className="px-3 py-2 text-left">Deadline</th>
                                <th className="px-3 py-2 text-left">Status</th>
                                <th className="px-3 py-2 text-left">Source</th>
                                <th className="px-3 py-2 text-left">Confidence</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {commitments.length === 0 ? (
                                <tr><td className="px-3 py-6 text-slate-400" colSpan={7}>No commitments yet.</td></tr>
                            ) : commitments.map((item) => (
                                <tr key={item.id}>
                                    <td className="px-3 py-2">{item.person?.display_name || item.person_name_raw || '—'}</td>
                                    <td className="px-3 py-2">
                                        <Link href={route('commitments.show', item.id)} className="text-indigo-700 hover:underline">{item.title}</Link>
                                    </td>
                                    <td className="px-3 py-2">{item.project?.name || '—'}</td>
                                    <td className="px-3 py-2">{item.deadline_at ? item.deadline_at.slice(0, 16).replace('T', ' ') : item.deadline_raw || '—'}</td>
                                    <td className="px-3 py-2">{item.status}</td>
                                    <td className="px-3 py-2">{item.source_type}</td>
                                    <td className="px-3 py-2">{item.confidence}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>
        </AdminLayout>
    );
}
