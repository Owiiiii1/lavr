import LavrAppShell from '@/telegram/LavrAppShell';
import { Head, Link } from '@inertiajs/react';

export default function Notifications({ inbox }) {
    const items = inbox?.items || [];

    return (
        <LavrAppShell>
            <Head title="Notifications" />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">LAVR</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">Notifications</h1>
                <p className="mt-2 text-sm text-slate-400">
                    {Number(inbox?.unread_count || 0) > 0
                        ? `${inbox.unread_count} непрочитанных`
                        : 'Нет непрочитанных уведомлений'}
                </p>

                {items.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">Пока тихо.</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {items.map((item) => (
                            <li key={item.id} className="rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                <p className="text-sm font-medium text-white">{item.title}</p>
                                {item.body ? <p className="mt-1 text-sm leading-6 text-slate-400">{item.body}</p> : null}
                            </li>
                        ))}
                    </ul>
                )}

                <Link href="/lavr/more" className="mt-8 inline-flex min-h-11 items-center text-sm text-sky-300">
                    Назад в More
                </Link>
            </div>
        </LavrAppShell>
    );
}
