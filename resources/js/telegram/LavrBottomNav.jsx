import { Link, usePage } from '@inertiajs/react';
import { FolderKanban, LayoutDashboard, MessageSquare, MoreHorizontal, Users } from 'lucide-react';

const ITEMS = [
    { href: '/lavr/today', match: (path) => path === '/lavr/today', label: 'Today', icon: LayoutDashboard },
    { href: '/lavr', match: (path) => path === '/lavr' || path.startsWith('/lavr/chats/'), label: 'Chat', icon: MessageSquare },
    { href: '/lavr/people', match: (path) => path.startsWith('/lavr/people'), label: 'People', icon: Users },
    { href: '/lavr/projects', match: (path) => path.startsWith('/lavr/projects'), label: 'Projects', icon: FolderKanban },
    { href: '/lavr/more', match: (path) => path === '/lavr/more' || path === '/lavr/meetings' || path === '/lavr/commitments', label: 'More', icon: MoreHorizontal },
];

export default function LavrBottomNav({ force = false }) {
    const page = usePage();
    const path = page.url.split('?')[0];

    return (
        <nav
            className={`lavr-bottom-nav ${force ? 'flex' : 'flex lg:hidden'}`}
            aria-label="LAVR"
        >
            {ITEMS.map((item) => {
                const Icon = item.icon;
                const active = item.match(path);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={`flex min-h-12 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-[11px] ${
                            active ? 'text-sky-200' : 'text-slate-400'
                        }`}
                    >
                        <Icon className="h-5 w-5" />
                        <span className="truncate">{item.label}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
