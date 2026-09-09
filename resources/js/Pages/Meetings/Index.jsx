import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

export default function MeetingsIndex() {
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
            route('meetings.index'),
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
        <AdminLayout title="Meetings">
            <Head title="Meetings" />
            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <p className="text-sm text-slate-600">
                            First-class meetings. Original transcript is the source of truth. Detected commitments are analysis only.
                        </p>
                        <button
                            type="button"
                            onClick={() => setShowCreate(true)}
                            className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            <Plus className="h-4 w-4" />
                            New Meeting
                        </button>
                    </div>
                    <form className="mt-4 grid gap-2 md:grid-cols-5" onSubmit={applyFilters}>
                        <input name="q" defaultValue={filters.q || ''} placeholder="Search" className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <select name="project_id" defaultValue={filters.project_id || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All projects</option>
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
                                <option value="">All analysis</option>
                                <option value="pending">pending</option>
                                <option value="processing">processing</option>
                                <option value="completed">completed</option>
                                <option value="failed">failed</option>
                            </select>
                            <button type="submit" className="rounded-lg border border-slate-300 px-3 text-sm">
                                Filter
                            </button>
                        </div>
                    </form>
                </section>

                <section className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold">Title</th>
                                <th className="px-4 py-3 text-left font-semibold">Project</th>
                                <th className="px-4 py-3 text-left font-semibold">Date</th>
                                <th className="px-4 py-3 text-left font-semibold">Participants</th>
                                <th className="px-4 py-3 text-left font-semibold">Source</th>
                                <th className="px-4 py-3 text-left font-semibold">Analysis</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {meetings.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                                        No meetings yet.
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
                                        <td className="px-4 py-3">{meeting.source_type}</td>
                                        <td className="px-4 py-3 capitalize">{meeting.analysis_status}</td>
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
                            <h2 className="text-base font-semibold text-slate-900">Import transcript</h2>
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
                                Title
                                <input value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                Date / time
                                <input type="datetime-local" value={form.data.started_at} onChange={(event) => form.setData('started_at', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                Project
                                <select value={form.data.project_id} onChange={(event) => form.setData('project_id', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">None</option>
                                    {projects.map((project) => (
                                        <option key={project.id} value={project.id}>{project.name}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium">
                                Organization
                                <select value={form.data.organization_id} onChange={(event) => form.setData('organization_id', event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">None</option>
                                    {organizations.map((organization) => (
                                        <option key={organization.id} value={organization.id}>{organization.name}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-sm font-medium">
                                Transcript file (.txt .vtt .srt .md, max {maxFileMb} MB)
                                <input type="file" accept=".txt,.vtt,.srt,.md,text/plain" onChange={(event) => form.setData('transcript', event.target.files?.[0] ?? null)} className="mt-1 w-full text-sm" />
                            </label>
                            <label className="block text-sm font-medium">
                                Or paste transcript
                                <textarea value={form.data.pasted_text} onChange={(event) => form.setData('pasted_text', event.target.value)} rows={6} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </label>
                            {form.errors.transcript && <p className="text-xs text-red-600">{form.errors.transcript}</p>}
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setShowCreate(false)} className="h-10 rounded-lg border px-4 text-sm">Cancel</button>
                                <button type="submit" disabled={form.processing} className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">Import</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
