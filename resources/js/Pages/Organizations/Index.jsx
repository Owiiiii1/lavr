import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

export default function OrganizationsIndex() {
    const { organizations = [] } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const form = useForm({ name: '', type: '', website: '', email: '' });

    return (
        <AdminLayout title="Organizations">
            <Head title="Organizations" />
            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <p className="text-sm text-slate-600">Companies and brands. Distinct from People.</p>
                        <button
                            type="button"
                            onClick={() => setShowCreate(true)}
                            className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white"
                        >
                            <Plus className="h-4 w-4" />
                            New Organization
                        </button>
                    </div>
                </section>
                <section className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold">Name</th>
                                <th className="px-4 py-3 text-left font-semibold">Type</th>
                                <th className="px-4 py-3 text-left font-semibold">Projects</th>
                                <th className="px-4 py-3 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {organizations.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                                        No organizations yet.
                                    </td>
                                </tr>
                            ) : (
                                organizations.map((organization) => (
                                    <tr
                                        key={organization.id}
                                        className="cursor-pointer hover:bg-amber-50/60"
                                        tabIndex={0}
                                        onClick={() => router.visit(route('organizations.show', organization.id))}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                router.visit(route('organizations.show', organization.id));
                                            }
                                        }}
                                    >
                                        <td className="px-4 py-3 font-medium">{organization.name}</td>
                                        <td className="px-4 py-3">{organization.type || '—'}</td>
                                        <td className="px-4 py-3">{organization.projects_count ?? 0}</td>
                                        <td className="px-4 py-3 capitalize">{organization.status}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </section>
            </div>
            {showCreate && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                        <div className="flex items-start justify-between">
                            <h2 className="text-base font-semibold">New Organization</h2>
                            <button type="button" onClick={() => setShowCreate(false)}>
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <form
                            className="mt-4 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(route('organizations.store'), { onSuccess: () => setShowCreate(false) });
                            }}
                        >
                            <label className="block text-sm font-medium">
                                Name
                                <input
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                                />
                                {form.errors.name && <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>}
                            </label>
                            <label className="block text-sm font-medium">
                                Type
                                <input
                                    value={form.data.type}
                                    onChange={(event) => form.setData('type', event.target.value)}
                                    className="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                                />
                            </label>
                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setShowCreate(false)} className="h-10 rounded-lg border px-4 text-sm">
                                    Cancel
                                </button>
                                <button type="submit" className="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white">
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
