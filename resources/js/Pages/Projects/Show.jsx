import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function ProjectShow() {
    const {
        locale = 'en',
        project,
        availableConversations = [],
        availableTopics = [],
        availableMemories = [],
        availableGroups = [],
        availablePeople = [],
        availableOrganizations = [],
        descriptionMax = 5000,
    } = usePage().props;
    const [editing, setEditing] = useState(false);
    const editForm = useForm({
        name: project.name,
        description: project.description ?? '',
        category: project.category ?? '',
        start_date: project.start_date ?? '',
        end_date: project.end_date ?? '',
        status: project.status,
    });
    const conversationForm = useForm({ conversation_id: '' });
    const topicForm = useForm({ topic_id: '' });
    const memoryForm = useForm({ memory_id: '' });
    const groupForm = useForm({ telegram_group_id: '' });
    const personForm = useForm({ person_id: '', role: '' });
    const organizationForm = useForm({ organization_id: '', role: '' });

    const text = {
        en: {
            back: 'Back to projects',
            edit: 'Edit',
            save: 'Save',
            cancel: 'Cancel',
            archive: 'Archive',
            restore: 'Restore',
            conversations: 'Conversations',
            topics: 'Topics',
            memories: 'Memories',
            groups: 'Telegram groups',
            addConversation: 'Add conversation',
            addTopic: 'Add topic',
            addMemory: 'Add memory',
            addGroup: 'Attach group',
            detach: 'Detach',
            empty: 'None attached.',
            name: 'Name',
            description: 'Description',
        },
        ru: {
            back: 'Back to projects',
            edit: 'Edit',
            save: 'Save',
            cancel: 'Cancel',
            archive: 'Archive',
            restore: 'Restore',
            conversations: 'Conversations',
            topics: 'Topics',
            memories: 'Memories',
            groups: 'Telegram groups',
            addConversation: 'Add conversation',
            addTopic: 'Add topic',
            addMemory: 'Add memory',
            addGroup: 'Attach group',
            detach: 'Detach',
            empty: 'None attached.',
            name: 'Name',
            description: 'Description',
        },
        uk: {
            back: 'Back to projects',
            edit: 'Edit',
            save: 'Save',
            cancel: 'Cancel',
            archive: 'Archive',
            restore: 'Restore',
            conversations: 'Conversations',
            topics: 'Topics',
            memories: 'Memories',
            groups: 'Telegram groups',
            addConversation: 'Add conversation',
            addTopic: 'Add topic',
            addMemory: 'Add memory',
            addGroup: 'Attach group',
            detach: 'Detach',
            empty: 'None attached.',
            name: 'Name',
            description: 'Description',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <AdminLayout title={project.name}>
            <Head title={project.name} />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Link href={route('projects.index')} className="text-sm text-indigo-700 hover:underline">
                        {t.back}
                    </Link>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setEditing((value) => !value)}
                            className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-sm"
                        >
                            {t.edit}
                        </button>
                        {project.status === 'archived' ? (
                            <Link
                                href={route('projects.restore', project.id)}
                                method="post"
                                as="button"
                                className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-sm"
                            >
                                {t.restore}
                            </Link>
                        ) : (
                            <Link
                                href={route('projects.archive', project.id)}
                                method="post"
                                as="button"
                                className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-sm"
                            >
                                {t.archive}
                            </Link>
                        )}
                    </div>
                </div>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    {editing ? (
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                editForm.patch(route('projects.update', project.id), {
                                    onSuccess: () => setEditing(false),
                                });
                            }}
                        >
                            <label className="block text-sm font-medium">
                                {t.name}
                                <input
                                    value={editForm.data.name}
                                    onChange={(event) => editForm.setData('name', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                                {editForm.errors.name && (
                                    <p className="mt-1 text-xs text-red-600">{editForm.errors.name}</p>
                                )}
                            </label>
                            <label className="block text-sm font-medium">
                                {t.description}
                                <textarea
                                    maxLength={descriptionMax}
                                    value={editForm.data.description}
                                    onChange={(event) => editForm.setData('description', event.target.value)}
                                    rows={4}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            <label className="block text-sm font-medium">
                                Category
                                <input
                                    value={editForm.data.category}
                                    onChange={(event) => editForm.setData('category', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </label>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="block text-sm font-medium">
                                    Start
                                    <input
                                        type="date"
                                        value={editForm.data.start_date}
                                        onChange={(event) => editForm.setData('start_date', event.target.value)}
                                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                    />
                                </label>
                                <label className="block text-sm font-medium">
                                    End
                                    <input
                                        type="date"
                                        value={editForm.data.end_date}
                                        onChange={(event) => editForm.setData('end_date', event.target.value)}
                                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                    />
                                </label>
                            </div>
                            <label className="block text-sm font-medium">
                                Status
                                <select
                                    value={editForm.data.status}
                                    onChange={(event) => editForm.setData('status', event.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                >
                                    <option value="active">active</option>
                                    <option value="paused">paused</option>
                                    <option value="completed">completed</option>
                                    <option value="archived">archived</option>
                                </select>
                            </label>
                            <div className="flex gap-2">
                                <button
                                    type="submit"
                                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white"
                                >
                                    {t.save}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setEditing(false)}
                                    className="inline-flex h-9 items-center rounded-lg border border-slate-300 px-3 text-sm"
                                >
                                    {t.cancel}
                                </button>
                            </div>
                        </form>
                    ) : (
                        <>
                            <p className="text-sm capitalize text-slate-500">{project.status}</p>
                            {project.category ? <p className="mt-1 text-xs text-slate-500">{project.category}</p> : null}
                            <p className="mt-2 whitespace-pre-wrap text-sm text-slate-800">
                                {project.description || '—'}
                            </p>
                        </>
                    )}
                </section>

                <RelationSection
                    title={t.conversations}
                    empty={t.empty}
                    items={project.conversations}
                    renderItem={(item) => (
                        <>
                            <span className="font-medium">{item.title}</span>
                            <span className="ml-2 text-xs text-slate-500">{item.last_activity_at || ''}</span>
                        </>
                    )}
                    detach={(item) => route('projects.conversations.destroy', [project.id, item.id])}
                    detachLabel={t.detach}
                    form={conversationForm}
                    field="conversation_id"
                    options={availableConversations.map((item) => ({
                        value: item.id,
                        label: item.title,
                    }))}
                    action={route('projects.conversations.store', project.id)}
                    addLabel={t.addConversation}
                />

                <RelationSection
                    title={t.topics}
                    empty={t.empty}
                    items={project.topics}
                    renderItem={(item) => <span className="font-medium">{item.name}</span>}
                    detach={(item) => route('projects.topics.destroy', [project.id, item.id])}
                    detachLabel={t.detach}
                    form={topicForm}
                    field="topic_id"
                    options={availableTopics.map((item) => ({ value: item.id, label: item.name }))}
                    action={route('projects.topics.store', project.id)}
                    addLabel={t.addTopic}
                />

                <RelationSection
                    title={t.memories}
                    empty={t.empty}
                    items={project.memories}
                    renderItem={(item) => (
                        <>
                            <p>{item.content}</p>
                            <p className="text-xs text-slate-500">
                                {item.kind} · {item.confidence}
                            </p>
                        </>
                    )}
                    detach={(item) => route('projects.memories.destroy', [project.id, item.id])}
                    detachLabel={t.detach}
                    form={memoryForm}
                    field="memory_id"
                    options={availableMemories.map((item) => ({
                        value: item.id,
                        label: `${item.kind} · ${item.content}`.slice(0, 120),
                    }))}
                    action={route('projects.memories.store', project.id)}
                    addLabel={t.addMemory}
                />

                <RelationSection
                    title={t.groups}
                    empty={t.empty}
                    items={project.groups ?? []}
                    renderItem={(item) => (
                        <>
                            <span className="font-medium">{item.title}</span>
                            <p className="text-xs text-slate-500">
                                {item.chat_type} · {item.status}
                            </p>
                        </>
                    )}
                    detach={(item) => route('projects.groups.destroy', [project.id, item.id])}
                    detachLabel={t.detach}
                    form={groupForm}
                    field="telegram_group_id"
                    options={availableGroups.map((item) => ({
                        value: item.id,
                        label: `${item.title} (${item.chat_type})`,
                    }))}
                    action={route('projects.groups.store', project.id)}
                    addLabel={t.addGroup}
                />

                <RelationSection
                    title="People"
                    empty={t.empty}
                    items={project.people ?? []}
                    renderItem={(item) => (
                        <>
                            <span className="font-medium">{item.display_name}</span>
                            {item.role ? <span className="ml-2 text-xs text-slate-500">{item.role}</span> : null}
                        </>
                    )}
                    detach={(item) => route('projects.people.destroy', [project.id, item.id])}
                    detachLabel={t.detach}
                    form={personForm}
                    field="person_id"
                    options={availablePeople.map((item) => ({ value: item.id, label: item.display_name }))}
                    action={route('projects.people.store', project.id)}
                    addLabel="Attach person"
                />

                <RelationSection
                    title="Organizations"
                    empty={t.empty}
                    items={project.organizations ?? []}
                    renderItem={(item) => (
                        <>
                            <span className="font-medium">{item.name}</span>
                            {item.role ? <span className="ml-2 text-xs text-slate-500">{item.role}</span> : null}
                        </>
                    )}
                    detach={(item) => route('projects.organizations.destroy', [project.id, item.id])}
                    detachLabel={t.detach}
                    form={organizationForm}
                    field="organization_id"
                    options={availableOrganizations.map((item) => ({ value: item.id, label: item.name }))}
                    action={route('projects.organizations.store', project.id)}
                    addLabel="Attach organization"
                />
            </div>
        </AdminLayout>
    );
}

function RelationSection({
    title,
    empty,
    items,
    renderItem,
    detach,
    detachLabel,
    form,
    field,
    options,
    action,
    addLabel,
}) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            {items.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">{empty}</p>
            ) : (
                <ul className="mt-3 space-y-2">
                    {items.map((item) => (
                        <li key={item.id} className="flex items-start justify-between gap-3 rounded-lg border border-slate-100 p-3 text-sm">
                            <div>{renderItem(item)}</div>
                            <Link
                                href={detach(item)}
                                method="delete"
                                as="button"
                                className="shrink-0 text-xs font-medium text-slate-600 hover:text-slate-900"
                            >
                                {detachLabel}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {options.length > 0 && (
                <form
                    className="mt-3 flex flex-wrap gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(action, { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <select
                        value={form.data[field]}
                        onChange={(event) => form.setData(field, event.target.value)}
                        className="min-w-56 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="">Select…</option>
                        {options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <button
                        type="submit"
                        disabled={form.processing || !form.data[field]}
                        className="inline-flex h-10 items-center rounded-lg border border-slate-300 px-3 text-sm disabled:opacity-50"
                    >
                        {addLabel}
                    </button>
                </form>
            )}
            {form.errors[field] && <p className="mt-2 text-xs text-red-600">{form.errors[field]}</p>}
        </section>
    );
}
