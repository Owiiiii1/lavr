import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function PersonShow() {
    const {
        person,
        employee,
        relationships = [],
        roleOptions = [],
        identityTypes = [],
        relationTypes = [],
        projects = [],
        organizations = [],
        people = [],
        knowledgeCandidates = [],
    } = usePage().props;
    const [editing, setEditing] = useState(false);
    const editForm = useForm({
        first_name: person.first_name ?? '',
        last_name: person.last_name ?? '',
        display_name: person.display_name ?? '',
        primary_email: person.primary_email ?? '',
        primary_phone: person.primary_phone ?? '',
        telegram_username: person.telegram_username ?? '',
        notes: person.notes ?? '',
        status: person.status,
        roles: person.roles ?? [],
    });
    const employeeForm = useForm({
        position: employee?.position ?? person.position ?? '',
        department: employee?.department ?? person.department ?? '',
        manager_person_id: employee?.manager_person_id ?? '',
        employment_status: employee?.employment_status ?? 'active',
        responsibilities: Array.isArray(person.responsibilities) ? person.responsibilities.join('\n') : '',
        areas_of_ownership: Array.isArray(person.areas_of_ownership) ? person.areas_of_ownership.join('\n') : '',
        notes: employee?.notes ?? '',
    });
    const identityForm = useForm({ type: identityTypes[0] || 'email', value: '' });
    const projectForm = useForm({ project_id: '', role: '' });
    const relationshipForm = useForm({
        relation_type: relationTypes[0] || 'works_for',
        object_type: 'organization',
        object_id: '',
        notes: '',
    });
    const knowledgeForm = useForm({ knowledge_entity_id: '' });
    const mergeForm = useForm({ source_person_id: '' });

    return (
        <AdminLayout title={person.display_name}>
            <Head title={person.display_name} />
            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Link href={route('people.index')} className="text-sm text-indigo-700 hover:underline">
                        Back to people
                    </Link>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => setEditing((value) => !value)} className="h-9 rounded-lg border px-3 text-sm">
                            Edit
                        </button>
                        {person.status === 'archived' ? (
                            <Link href={route('people.restore', person.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">
                                Restore
                            </Link>
                        ) : (
                            <Link href={route('people.archive', person.id)} method="post" as="button" className="h-9 rounded-lg border px-3 text-sm">
                                Archive
                            </Link>
                        )}
                    </div>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    {editing ? (
                        <form
                            className="grid gap-3 md:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                editForm.patch(route('people.update', person.id), { onSuccess: () => setEditing(false) });
                            }}
                        >
                            {['first_name', 'last_name', 'display_name', 'primary_email', 'primary_phone', 'telegram_username'].map((field) => (
                                <label key={field} className="block text-sm font-medium">
                                    {field}
                                    <input
                                        value={editForm.data[field]}
                                        onChange={(event) => editForm.setData(field, event.target.value)}
                                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                    />
                                </label>
                            ))}
                            <label className="block text-sm font-medium">
                                status
                                <select
                                    value={editForm.data.status}
                                    onChange={(event) => editForm.setData('status', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option value="active">active</option>
                                    <option value="inactive">inactive</option>
                                    <option value="archived">archived</option>
                                </select>
                            </label>
                            <label className="block text-sm font-medium md:col-span-2">
                                roles
                                <select
                                    multiple
                                    value={editForm.data.roles}
                                    onChange={(event) =>
                                        editForm.setData(
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
                            <label className="block text-sm font-medium md:col-span-2">
                                notes
                                <textarea
                                    value={editForm.data.notes}
                                    onChange={(event) => editForm.setData('notes', event.target.value)}
                                    rows={3}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            {editForm.errors.display_name && <p className="text-xs text-red-600">{editForm.errors.display_name}</p>}
                            <button type="submit" className="h-9 rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white">
                                Save
                            </button>
                        </form>
                    ) : (
                        <div className="space-y-2 text-sm">
                            <p className="capitalize text-slate-500">{person.status}</p>
                            <p>Roles: {(person.roles || []).join(', ') || '—'}</p>
                            <p>Email: {person.primary_email || '—'}</p>
                            <p>Phone: {person.primary_phone || '—'}</p>
                            <p>Telegram: {person.telegram_username || '—'}</p>
                            <p>Organization: {person.primary_organization || '—'}</p>
                            <p className="whitespace-pre-wrap">{person.notes || '—'}</p>
                        </div>
                    )}
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Employee profile</h2>
                    <form
                        className="mt-3 grid gap-3 md:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            employeeForm.patch(route('people.employee.update', person.id));
                        }}
                    >
                        <label className="text-sm">
                            Position
                            <input
                                value={employeeForm.data.position}
                                onChange={(event) => employeeForm.setData('position', event.target.value)}
                                className="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                            />
                        </label>
                        <label className="text-sm">
                            Department
                            <input
                                value={employeeForm.data.department}
                                onChange={(event) => employeeForm.setData('department', event.target.value)}
                                className="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                            />
                        </label>
                        <label className="text-sm md:col-span-2">
                            Responsibilities
                            <textarea
                                value={employeeForm.data.responsibilities}
                                onChange={(event) => employeeForm.setData('responsibilities', event.target.value)}
                                rows={3}
                                className="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                            />
                        </label>
                        <button type="submit" className="h-9 rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white">
                            Save profile
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Identities</h2>
                    <ul className="mt-2 space-y-1 text-sm">
                        {(person.identities || []).map((identity) => (
                            <li key={identity.id}>
                                {identity.type}: {identity.value}
                                {identity.is_primary ? ' · primary' : ''}
                            </li>
                        ))}
                    </ul>
                    <form
                        className="mt-3 flex flex-wrap gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            identityForm.post(route('people.identities.store', person.id), { onSuccess: () => identityForm.reset('value') });
                        }}
                    >
                        <select
                            value={identityForm.data.type}
                            onChange={(event) => identityForm.setData('type', event.target.value)}
                            className="rounded-lg border px-3 py-2 text-sm"
                        >
                            {identityTypes.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                        <input
                            value={identityForm.data.value}
                            onChange={(event) => identityForm.setData('value', event.target.value)}
                            className="min-w-48 flex-1 rounded-lg border px-3 py-2 text-sm"
                        />
                        <button type="submit" className="h-10 rounded-lg border px-3 text-sm">
                            Add
                        </button>
                    </form>
                    {identityForm.errors.value && <p className="mt-1 text-xs text-red-600">{identityForm.errors.value}</p>}
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Projects</h2>
                    <ul className="mt-2 space-y-2 text-sm">
                        {(person.projects || []).map((project) => (
                            <li key={project.id} className="flex justify-between gap-3">
                                <Link href={route('projects.show', project.id)} className="text-indigo-700 hover:underline">
                                    {project.name}
                                </Link>
                                <Link href={route('people.projects.destroy', [person.id, project.id])} method="delete" as="button" className="text-xs">
                                    Detach
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="mt-3 flex flex-wrap gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            projectForm.post(route('people.projects.store', person.id), { onSuccess: () => projectForm.reset() });
                        }}
                    >
                        <select
                            value={projectForm.data.project_id}
                            onChange={(event) => projectForm.setData('project_id', event.target.value)}
                            className="min-w-48 flex-1 rounded-lg border px-3 py-2 text-sm"
                        >
                            <option value="">Select…</option>
                            {projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.name}
                                </option>
                            ))}
                        </select>
                        <input
                            placeholder="role"
                            value={projectForm.data.role}
                            onChange={(event) => projectForm.setData('role', event.target.value)}
                            className="w-32 rounded-lg border px-3 py-2 text-sm"
                        />
                        <button type="submit" className="h-10 rounded-lg border px-3 text-sm">
                            Attach
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Relationships</h2>
                    <ul className="mt-2 space-y-1 text-sm">
                        {relationships.map((row) => (
                            <li key={row.id}>
                                {row.subject_type}:{row.subject_id} {row.relation_type} {row.object_type}:{row.object_id}
                            </li>
                        ))}
                    </ul>
                    <form
                        className="mt-3 grid gap-2 md:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            relationshipForm.post(route('people.relationships.store', person.id), { onSuccess: () => relationshipForm.reset('object_id') });
                        }}
                    >
                        <select
                            value={relationshipForm.data.relation_type}
                            onChange={(event) => relationshipForm.setData('relation_type', event.target.value)}
                            className="rounded-lg border px-3 py-2 text-sm"
                        >
                            {relationTypes.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                        <select
                            value={relationshipForm.data.object_type}
                            onChange={(event) => relationshipForm.setData('object_type', event.target.value)}
                            className="rounded-lg border px-3 py-2 text-sm"
                        >
                            <option value="organization">organization</option>
                            <option value="person">person</option>
                        </select>
                        <select
                            value={relationshipForm.data.object_id}
                            onChange={(event) => relationshipForm.setData('object_id', event.target.value)}
                            className="rounded-lg border px-3 py-2 text-sm"
                        >
                            <option value="">Select…</option>
                            {(relationshipForm.data.object_type === 'organization' ? organizations : people).map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name || item.display_name}
                                </option>
                            ))}
                        </select>
                        <button type="submit" className="h-10 rounded-lg border px-3 text-sm">
                            Add
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Knowledge links</h2>
                    <ul className="mt-2 text-sm">
                        {(person.knowledge_links || []).map((item) => (
                            <li key={item.id}>{item.name}</li>
                        ))}
                    </ul>
                    <form
                        className="mt-3 flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            knowledgeForm.post(route('people.knowledge.store', person.id));
                        }}
                    >
                        <select
                            value={knowledgeForm.data.knowledge_entity_id}
                            onChange={(event) => knowledgeForm.setData('knowledge_entity_id', event.target.value)}
                            className="flex-1 rounded-lg border px-3 py-2 text-sm"
                        >
                            <option value="">Select…</option>
                            {knowledgeCandidates.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                        <button type="submit" className="h-10 rounded-lg border px-3 text-sm">
                            Link
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold">Merge duplicate</h2>
                    <p className="mt-1 text-xs text-slate-500">Source person is archived. Identities and projects move here.</p>
                    <form
                        className="mt-3 flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            mergeForm.post(route('people.merge', person.id));
                        }}
                    >
                        <select
                            value={mergeForm.data.source_person_id}
                            onChange={(event) => mergeForm.setData('source_person_id', event.target.value)}
                            className="flex-1 rounded-lg border px-3 py-2 text-sm"
                        >
                            <option value="">Select source…</option>
                            {people.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.display_name}
                                </option>
                            ))}
                        </select>
                        <button type="submit" className="h-10 rounded-lg border px-3 text-sm">
                            Merge
                        </button>
                    </form>
                    {mergeForm.errors.source_person_id && <p className="mt-1 text-xs text-red-600">{mergeForm.errors.source_person_id}</p>}
                </section>
            </div>
        </AdminLayout>
    );
}
