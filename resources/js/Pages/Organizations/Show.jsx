import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function OrganizationShow() {
    const { organization, people = [], projects = [], knowledgeCandidates = [] } = usePage().props;
    const [editing, setEditing] = useState(false);
    const editForm = useForm({
        name: organization.name,
        type: organization.type ?? '',
        website: organization.website ?? '',
        email: organization.email ?? '',
        phone: organization.phone ?? '',
        notes: organization.notes ?? '',
        status: organization.status,
    });
    const projectForm = useForm({ project_id: '', role: '' });
    const knowledgeForm = useForm({ knowledge_entity_id: '' });

    return (
        <AdminLayout title={organization.name}>
            <Head title={organization.name} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Link href={route('organizations.index')} className="text-sm text-indigo-700 hover:underline">
                        Back to organizations
                    </Link>
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setEditing((value) => !value)} className="h-9 rounded-lg border px-3 text-sm">
                            Edit
                        </button>
                        {organization.status === 'archived' ? (
                            <Link href={route('organizations.restore', organization.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">
                                Restore
                            </Link>
                        ) : (
                            <Link href={route('organizations.archive', organization.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">
                                Archive
                            </Link>
                        )}
                    </div>
                </div>

                <section className="rounded-xl border bg-white p-4 text-sm">
                    {editing ? (
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                editForm.patch(route('organizations.update', organization.id), { onSuccess: () => setEditing(false) });
                            }}
                        >
                            {['name', 'type', 'website', 'email', 'phone'].map((field) => (
                                <label key={field} className="block font-medium">
                                    {field}
                                    <input
                                        value={editForm.data[field]}
                                        onChange={(event) => editForm.setData(field, event.target.value)}
                                        className="mt-1 w-full rounded-lg border px-3 py-2"
                                    />
                                </label>
                            ))}
                            <label className="block font-medium">
                                notes
                                <textarea
                                    value={editForm.data.notes}
                                    onChange={(event) => editForm.setData('notes', event.target.value)}
                                    rows={3}
                                    className="mt-1 w-full rounded-lg border px-3 py-2"
                                />
                            </label>
                            <button type="submit" className="h-9 rounded-lg bg-indigo-600 px-3 font-semibold text-white">
                                Save
                            </button>
                        </form>
                    ) : (
                        <>
                            <p className="capitalize text-slate-500">{organization.status}</p>
                            <p className="mt-2">{organization.type || '—'}</p>
                            <p>{organization.website || '—'}</p>
                            <p>{organization.email || '—'}</p>
                            <p className="whitespace-pre-wrap">{organization.notes || '—'}</p>
                        </>
                    )}
                </section>

                <section className="rounded-xl border bg-white p-4 text-sm">
                    <h2 className="font-semibold">Related people</h2>
                    <ul className="mt-2 space-y-1">
                        {people.length === 0 ? <li className="text-slate-500">None yet.</li> : people.map((person) => (
                            <li key={person.id}>
                                <Link href={route('people.show', person.id)} className="text-indigo-700 hover:underline">
                                    {person.display_name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-xl border bg-white p-4 text-sm">
                    <h2 className="font-semibold">Projects</h2>
                    <ul className="mt-2 space-y-2">
                        {(organization.projects || []).map((project) => (
                            <li key={project.id} className="flex justify-between gap-3">
                                <Link href={route('projects.show', project.id)} className="text-indigo-700 hover:underline">
                                    {project.name}
                                </Link>
                                <Link href={route('organizations.projects.destroy', [organization.id, project.id])} method="delete" as="button" className="text-xs">
                                    Detach
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="mt-3 flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            projectForm.post(route('organizations.projects.store', organization.id), { onSuccess: () => projectForm.reset() });
                        }}
                    >
                        <select
                            value={projectForm.data.project_id}
                            onChange={(event) => projectForm.setData('project_id', event.target.value)}
                            className="flex-1 rounded-lg border px-3 py-2"
                        >
                            <option value="">Select…</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                        <button type="submit" className="h-10 rounded-lg border px-3">
                            Attach
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border bg-white p-4 text-sm">
                    <h2 className="font-semibold">Knowledge links</h2>
                    <form
                        className="mt-3 flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            knowledgeForm.post(route('organizations.knowledge.store', organization.id));
                        }}
                    >
                        <select
                            value={knowledgeForm.data.knowledge_entity_id}
                            onChange={(event) => knowledgeForm.setData('knowledge_entity_id', event.target.value)}
                            className="flex-1 rounded-lg border px-3 py-2"
                        >
                            <option value="">Select…</option>
                            {knowledgeCandidates.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                        <button type="submit" className="h-10 rounded-lg border px-3">
                            Link
                        </button>
                    </form>
                </section>
            </div>
        </AdminLayout>
    );
}
