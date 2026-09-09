import CabinetLayout from '@/Layouts/CabinetLayout';
import { Head, usePage } from '@inertiajs/react';

export default function CabinetIndex() {
    const { user = {}, conversations = [] } = usePage().props;

    const formatActivity = (iso) => {
        if (!iso) {
            return '—';
        }

        try {
            return new Date(iso).toLocaleString();
        } catch {
            return iso;
        }
    };

    return (
        <CabinetLayout title="LAVR Cabinet">
            <Head title="LAVR Cabinet" />

            <div className="space-y-6">
                <div className="app-widget space-y-4 p-6">
                    <p className="text-sm text-slate-600">
                        Welcome to your personal LAVR workspace. Chat input will arrive in a later milestone.
                    </p>

                    <dl className="grid gap-4 sm:grid-cols-2">
                        <InfoRow label="Name" value={user.name} />
                        <InfoRow label="Email" value={user.email} />
                        <InfoRow label="Role" value={user.role} />
                        <InfoRow label="Status" value={user.status} />
                        <InfoRow label="Timezone" value={user.timezone} />
                    </dl>
                </div>

                <div className="app-widget p-6">
                    <h2 className="text-base font-semibold text-slate-900">Chats</h2>
                    <p className="mt-1 text-sm text-slate-600">
                        The same conversation catalog as Telegram.
                    </p>

                    {conversations.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">No chats yet.</p>
                    ) : (
                        <ul className="mt-4 divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200 bg-white">
                            {conversations.map((conversation) => (
                                <li key={conversation.id} className="flex items-center justify-between gap-4 px-4 py-3">
                                    <span className="font-medium text-slate-900">{conversation.title}</span>
                                    <span className="text-xs text-slate-500">
                                        {formatActivity(conversation.last_activity_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </CabinetLayout>
    );
}

function InfoRow({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white px-4 py-3">
            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-1 text-sm font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}
