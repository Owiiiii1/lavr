import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Menu, MessageSquarePlus, Sparkles, UserCircle2, X } from 'lucide-react';
import { useState } from 'react';

export default function CabinetLayout({ children }) {
    const { auth, owlAdmin = {}, conversations = [], conversation = null, user: pageUser = {} } = usePage().props;
    const user = auth?.user;
    const brandName = owlAdmin?.brand_name ?? 'LAVR';
    const logoPath = owlAdmin?.logo_path ?? '/images/company-logo.svg';
    const currentId = conversation?.id ?? null;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    const formatActivity = (iso) => {
        if (!iso) {
            return '';
        }

        try {
            return new Date(iso).toLocaleString(undefined, {
                timeZone: pageUser.timezone || user?.timezone || undefined,
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch {
            return iso;
        }
    };

    const sidebar = (
        <div className="flex h-full flex-col bg-white">
            <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-4">
                <Link href={route('cabinet.index')} className="flex min-w-0 items-center gap-3">
                    <img src={logoPath} alt={brandName} className="h-9 w-9 rounded-xl shadow-sm" />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900">{brandName}</p>
                        <p className="text-[10px] uppercase tracking-[0.18em] text-slate-500">Cabinet</p>
                    </div>
                </Link>
                <button
                    type="button"
                    className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                >
                    <X className="h-4 w-4" />
                </button>
            </div>

            <div className="p-3">
                <button
                    type="button"
                    onClick={() => router.post(route('cabinet.chats.store'))}
                    className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white transition hover:bg-indigo-700"
                >
                    <MessageSquarePlus className="h-4 w-4" />
                    Новый чат
                </button>
            </div>

            <nav className="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                {conversations.length === 0 ? (
                    <p className="px-3 py-6 text-sm text-slate-500">Пока нет чатов.</p>
                ) : (
                    <ul className="space-y-1">
                        {conversations.map((item) => {
                            const active = Number(item.id) === Number(currentId);

                            return (
                                <li key={item.id}>
                                    <Link
                                        href={route('cabinet.chats.show', item.id)}
                                        onClick={() => setSidebarOpen(false)}
                                        className={`block rounded-lg px-3 py-2 transition ${
                                            active
                                                ? 'bg-indigo-50 text-indigo-900 ring-1 ring-indigo-200'
                                                : 'text-slate-700 hover:bg-slate-100'
                                        }`}
                                    >
                                        <span className="block truncate text-sm font-medium">{item.title}</span>
                                        {item.last_activity_at ? (
                                            <span className="mt-0.5 block text-[11px] text-slate-500">
                                                {formatActivity(item.last_activity_at)}
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </nav>

            <div className="space-y-1 border-t border-slate-200 p-3">
                <Link
                    href={route('cabinet.ai-settings.edit')}
                    className="inline-flex h-10 w-full items-center gap-2 rounded-lg px-3 text-sm font-medium text-slate-700 hover:bg-slate-100"
                >
                    <Sparkles className="h-4 w-4" />
                    AI Settings
                </Link>
                <div className="flex items-center gap-2 px-3 py-2 text-sm text-slate-600">
                    <UserCircle2 className="h-4 w-4" />
                    <span className="truncate">{user?.name ?? 'User'}</span>
                </div>
                <button
                    type="button"
                    onClick={() => router.post(route('logout'))}
                    className="inline-flex h-10 w-full items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    <LogOut className="h-4 w-4" />
                    Log out
                </button>
            </div>
        </div>
    );

    return (
        <div className="h-screen bg-[#F4EFE4] text-slate-900">
            <div className="flex h-full">
                <aside className="hidden w-72 shrink-0 border-r border-slate-200 lg:block">{sidebar}</aside>

                {sidebarOpen ? (
                    <div className="fixed inset-0 z-40 lg:hidden">
                        <button
                            type="button"
                            className="absolute inset-0 bg-slate-900/40"
                            onClick={() => setSidebarOpen(false)}
                        />
                        <aside className="relative z-50 h-full w-72 max-w-[85vw] shadow-xl">{sidebar}</aside>
                    </div>
                ) : null}

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center gap-3 border-b border-slate-200/80 bg-white/90 px-4 py-3 lg:hidden">
                        <button
                            type="button"
                            className="rounded-lg p-2 text-slate-700 hover:bg-slate-100"
                            onClick={() => setSidebarOpen(true)}
                        >
                            <Menu className="h-5 w-5" />
                        </button>
                        <span className="truncate text-sm font-semibold">{conversation?.title ?? brandName}</span>
                    </header>
                    <main className="min-h-0 flex-1 overflow-hidden">{children}</main>
                </div>
            </div>
        </div>
    );
}
