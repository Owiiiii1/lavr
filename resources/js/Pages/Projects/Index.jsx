import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

export default function ProjectsIndex() {
    const { locale = 'en', projects = [] } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const form = useForm({ name: '', description: '' });

    const text = {
        en: {
            title: 'Projects',
            hint: 'Owner work containers. Relations only — chats, topics and memories stay where they are.',
            add: 'New Project',
            empty: 'No projects yet.',
            name: 'Name',
            description: 'Description',
            status: 'Status',
            conversations: 'Conversations',
            topics: 'Topics',
            memories: 'Memories',
            updated: 'Updated',
            archive: 'Archive',
            restore: 'Restore',
            cancel: 'Cancel',
            save: 'Create',
        },
        ru: {
            title: 'Projects',
            hint: 'Рабочие контейнеры owner. Только связи — чаты, темы и memories остаются на месте.',
            add: 'New Project',
            empty: 'No projects yet.',
            name: 'Name',
            description: 'Description',
            status: 'Status',
            conversations: 'Conversations',
            topics: 'Topics',
            memories: 'Memories',
            updated: 'Updated',
            archive: 'Archive',
            restore: 'Restore',
            cancel: 'Cancel',
            save: 'Create',
        },
        uk: {
            title: 'Projects',
            hint: 'Робочі контейнери owner. Лише зв’язки — чати, теми і memories лишаються на місці.',
            add: 'New Project',
            empty: 'No projects yet.',
            name: 'Name',
            description: 'Description',
            status: 'Status',
            conversations: 'Conversations',
            topics: 'Topics',
            memories: 'Memories',
            updated: 'Updated',
            archive: 'Archive',
            restore: 'Restore',
            cancel: 'Cancel',
            save: 'Create',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />

            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <p className="text-sm text-slate-600">{t.hint}</p>
                        <button
                            type="button"
                            onClick={() => setShowCreate(true)}
                            className="inline-flex h-10 items-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            <Plus className="h-4 w-4" />
                            {t.add}
                        </button>
                    </div>
                </section>

                <section className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold">{t.name}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.status}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.conversations}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.topics}</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.memories}</th>
                                <th className="px-4 py-3 text-left font-semibold">People</th>
                                <th className="px-4 py-3 text-left font-semibold">Orgs</th>
                                <th className="px-4 py-3 text-left font-semibold">{t.updated}</th>
                                <th className="px-4 py-3 text-left font-semibold" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {projects.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="px-4 py-6 text-center text-slate-400">
                                        {t.empty}
                                    </td>
                                </tr>
                            ) : (
                                projects.map((project) => (
                                    <tr
                                        key={project.id}
                                        className="cursor-pointer transition hover:bg-amber-50/60"
                                        tabIndex={0}
                                        onClick={() => router.visit(route('projects.show', project.id))}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                router.visit(route('projects.show', project.id));
                                            }
                                        }}
                                    >
                                        <td className="px-4 py-3 font-medium text-slate-900">{project.name}</td>
                                        <td className="px-4 py-3 capitalize">{project.status}</td>
                                        <td className="px-4 py-3">{project.conversations_count}</td>
                                        <td className="px-4 py-3">{project.topics_count}</td>
                                        <td className="px-4 py-3">{project.memories_count}</td>
                                        <td className="px-4 py-3">{project.people_count ?? 0}</td>
                                        <td className="px-4 py-3">{project.organizations_count ?? 0}</td>
                                        <td className="px-4 py-3 text-slate-500">
                                            {project.updated_at ? new Date(project.updated_at).toLocaleString() : '—'}
                                        </td>
                                        <td
                                            className="px-4 py-3"
                                            onClick={(event) => event.stopPropagation()}
                                            onKeyDown={(event) => event.stopPropagation()}
                                        >
                                            {project.status === 'archived' ? (
                                                <Link
                                                    href={route('projects.restore', project.id)}
                                                    method="post"
                                                    as="button"
                                                    className="inline-flex h-8 items-center rounded-lg border border-slate-300 px-3 text-xs font-medium hover:bg-slate-50"
                                                >
                                                    {t.restore}
                                                </Link>
                                            ) : (
                                                <Link
                                                    href={route('projects.archive', project.id)}
                                                    method="post"
                                                    as="button"
                                                    className="inline-flex h-8 items-center rounded-lg border border-slate-300 px-3 text-xs font-medium hover:bg-slate-50"
                                                >
                                                    {t.archive}
                                                </Link>
                                            )}
                                        </td>
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
                            <h2 className="text-base font-semibold text-slate-900">{t.add}</h2>
                            <button
                                type="button"
                                onClick={() => setShowCreate(false)}
                                className="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                        <form
                            className="mt-4 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(route('projects.store'), {
                                    onSuccess: () => setShowCreate(false),
                                });
                            }}
                        >
                            <label className="block text-sm font-medium text-slate-700">
                                {t.name}
                                <input
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                                {form.errors.name && <p className="mt-1 text-xs text-red-600">{form.errors.name}</p>}
                            </label>
                            <label className="block text-sm font-medium text-slate-700">
                                {t.description}
                                <textarea
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                    rows={4}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowCreate(false)}
                                    className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm"
                                >
                                    {t.cancel}
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="inline-flex h-10 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white disabled:opacity-60"
                                >
                                    {t.save}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
