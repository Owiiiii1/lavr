import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

export default function PeopleIndex() {
    const { people = [], filters = {}, roleOptions = [], projects = [] } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const form = useForm({
        first_name: '',
        last_name: '',
        display_name: '',
        primary_email: '',
        roles: [],
    });

    const applyFilters = (next) => {
        router.get(route('people.index'), next, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="People">
            <Head title="People" />

            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <p className="text-sm text-slate-600">Canonical people. Roles and projects live here, not in Memory.</p>
                        <button
                            type="button"
                            onClick={() => setShowCreate(true)}
                            className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            <Plus className="h-4 w-4" />
                            New Person
                        </button>
                    </div>
                    <form
                        className="mt-4 grid gap-2 md:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({
                                q: event.target.q.value,
                                role: event.target.role.value,
                                project_id: event.target.project_id.value,
                                status: event.target.status.value,
                            });
                        }}
                    >
                        <input
                            name="q"
                            defaultValue={filters.q || ''}
                            placeholder="Search"
                            className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        />
                        <select name="role" defaultValue={filters.role || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All roles</option>
                            {roleOptions.map((role) => (
                                <option key={role} value={role}>
                                    {role}
                                </option>
                            ))}
                        </select>
                        <select
                            name="project_id"
                            defaultValue={filters.project_id || ''}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                        >
                            <option value="">All projects</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                        <div className="flex gap-2">
                            <select
                                name="status"
                                defaultValue={filters.status || ''}
                                className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                            >
                                <option value="">All statuses</option>
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="archived">archived</option>
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
                                <th className="px-4 py-3 text-left font-semibold">Name</th>
                                <th className="px-4 py-3 text-left font-semibold">Roles</th>
                                <th className="px-4 py-3 text-left font-semibold">Organization</th>
                                <th className="px-4 py-3 text-left font-semibold">Position</th>
                                <th className="px-4 py-3 text-left font-semibold">Projects</th>
                                <th className="px-4 py-3 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {people.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                                        No people yet.
                                    </td>
                                </tr>
                            ) : (
                                people.map((person) => (
                                    <tr
                                        key={person.id}
                                        className="cursor-pointer transition hover:bg-amber-50/60"
                                        tabIndex={0}
                                        onClick={() => router.visit(route('people.show', person.id))}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                router.visit(route('people.show', person.id));
                                            }
                                        }}
                                    >
                                        <td className="px-4 py-3 font-medium text-slate-900">{person.display_name}</td>
                                        <td className="px-4 py-3">{(person.roles || []).join(', ') || '—'}</td>
                                        <td className="px-4 py-3">{person.primary_organization || '—'}</td>
                                        <td className="px-4 py-3">{person.position || '—'}</td>
                                        <td className="px-4 py-3">{(person.projects || []).map((item) => item.name).join(', ') || '—'}</td>
                                        <td className="px-4 py-3 capitalize">{person.status}</td>
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
                            <h2 className="text-base font-semibold text-slate-900">New Person</h2>
                            <button
                                type="button"
                                onClick={() => setShowCreate(false)}
                                className="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <form
                            className="mt-4 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(route('people.store'), { onSuccess: () => setShowCreate(false) });
                            }}
                        >
                            <label className="block text-sm font-medium">
                                First name
                                <input
                                    value={form.data.first_name}
                                    onChange={(event) => form.setData('first_name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            <label className="block text-sm font-medium">
                                Last name
                                <input
                                    value={form.data.last_name}
                                    onChange={(event) => form.setData('last_name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            <label className="block text-sm font-medium">
                                Display name
                                <input
                                    value={form.data.display_name}
                                    onChange={(event) => form.setData('display_name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                                {form.errors.display_name && <p className="mt-1 text-xs text-red-600">{form.errors.display_name}</p>}
                            </label>
                            <label className="block text-sm font-medium">
                                Email
                                <input
                                    value={form.data.primary_email}
                                    onChange={(event) => form.setData('primary_email', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            <label className="block text-sm font-medium">
                                Roles
                                <select
                                    multiple
                                    value={form.data.roles}
                                    onChange={(event) =>
                                        form.setData(
                                            'roles',
                                            Array.from(event.target.selectedOptions).map((option) => option.value),
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                >
                                    {roleOptions.map((role) => (
                                        <option key={role} value={role}>
                                            {role}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setShowCreate(false)} className="h-10 rounded-lg border px-4 text-sm">
                                    Cancel
                                </button>
                                <button type="submit" disabled={form.processing} className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">
                                    Create
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
