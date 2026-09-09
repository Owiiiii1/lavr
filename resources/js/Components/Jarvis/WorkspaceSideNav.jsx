import { useTranslation } from '@/locales/useTranslation';
import { Link, router } from '@inertiajs/react';
import { HardDrive, MessageSquarePlus, X } from 'lucide-react';

function formatWhen(iso, timezone, bcp47) {
    if (!iso) {
        return '';
    }

    try {
        return new Date(iso).toLocaleString(bcp47, {
            timeZone: timezone || undefined,
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

export default function WorkspaceSideNav({
    brandName = 'LAVR',
    conversations = [],
    currentConversationId = null,
    storageActive = false,
    timezone,
    onClose,
}) {
    const { t, bcp47 } = useTranslation();
    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex items-center justify-between px-4 py-4">
                <div>
                    <p className="text-sm font-semibold text-white">{brandName}</p>
                    <p className="text-[10px] uppercase tracking-[0.18em] text-slate-500">{t('common.workspace')}</p>
                </div>
                {onClose ? (
                    <button type="button" className="rounded-lg p-2 text-slate-400 hover:bg-white/10 lg:hidden" onClick={onClose} aria-label={t('chat.closeSidebar')}>
                        <X className="h-4 w-4" />
                    </button>
                ) : null}
            </div>
            <div className="space-y-2 px-3">
                <button
                    type="button"
                    onClick={() => router.post(route('jarvis.chats.store'))}
                    className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-sky-500/90 px-3 text-sm font-semibold text-white hover:bg-sky-400"
                >
                    <MessageSquarePlus className="h-4 w-4" />
                    {t('chat.newChat')}
                </button>
                <Link
                    href={route('jarvis.storage.index')}
                    onClick={onClose}
                    className={`inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl px-3 text-sm font-semibold ${
                        storageActive
                            ? 'bg-white/10 text-white ring-1 ring-sky-400/30'
                            : 'border border-white/10 text-slate-200 hover:bg-white/5'
                    }`}
                >
                    <HardDrive className="h-4 w-4" />
                    {t('chat.storage')}
                </Link>
            </div>
            <nav className="mt-3 min-h-0 flex-1 overflow-y-auto px-2 pb-3">
                {conversations.length === 0 ? (
                    <p className="px-3 py-6 text-sm text-slate-500">{t('chat.noChats')}</p>
                ) : (
                    <ul className="space-y-1">
                        {conversations.map((item) => {
                            const active = !storageActive && Number(item.id) === Number(currentConversationId);

                            return (
                                <li key={item.id}>
                                    <Link
                                        href={route('jarvis.chats.show', item.id)}
                                        onClick={onClose}
                                        className={`block rounded-xl px-3 py-2 transition ${
                                            active
                                                ? 'bg-white/10 text-white ring-1 ring-sky-400/30'
                                                : 'text-slate-300 hover:bg-white/5'
                                        }`}
                                    >
                                        <span className="block truncate text-sm font-medium">{item.title}</span>
                                        {item.last_activity_at ? (
                                            <span className="mt-0.5 block text-[11px] text-slate-500">
                                                {formatWhen(item.last_activity_at, timezone, bcp47)}
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </nav>
        </div>
    );
}
