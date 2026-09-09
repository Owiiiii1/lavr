import LavrAppShell from '@/telegram/LavrAppShell';
import { Head, Link, router } from '@inertiajs/react';

const LINKS = [
    { href: '/lavr/meetings', label: 'Meetings', hint: 'Phase 5' },
    { href: '/lavr/commitments', label: 'Commitments', hint: 'Phase 6' },
    { href: '/lavr?reports=1', label: 'Reports', hint: 'Текущие отчеты' },
    { href: '/lavr?settings=knowledge', label: 'Knowledge', hint: 'Индекс, не People' },
    { href: '/lavr?notifications=1', label: 'Notifications', hint: 'Входящие' },
    { href: '/lavr?settings=profile', label: 'Settings', hint: 'Профиль и ассистент' },
];

export default function More() {
    return (
        <LavrAppShell>
            <Head title="More" />
            <div className="jarvis-workspace min-h-[100dvh] px-4 pb-6 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">More</h1>
                <ul className="mt-6 space-y-2">
                    {LINKS.map((item) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                className="flex min-h-14 items-center justify-between rounded-2xl border border-white/10 bg-white/5 px-4"
                            >
                                <span className="text-sm font-medium text-white">{item.label}</span>
                                <span className="text-xs text-slate-400">{item.hint}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
                <button
                    type="button"
                    className="mt-8 text-sm text-slate-400"
                    onClick={() => router.post(route('logout'))}
                >
                    Выйти
                </button>
            </div>
        </LavrAppShell>
    );
}
