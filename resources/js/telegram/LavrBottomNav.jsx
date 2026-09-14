import { useTranslation } from '@/locales/useTranslation';
import { Link, usePage } from '@inertiajs/react';
import { FolderKanban, LayoutDashboard, MessageSquare, MoreHorizontal, Users } from 'lucide-react';

export default function LavrBottomNav({ force = false }) {
    const page = usePage();
    const { t } = useTranslation();
    const path = page.url.split('?')[0];
    const items = [
        { href: '/lavr/today', match: (current) => current === '/lavr/today', label: t('navigation.today'), icon: LayoutDashboard },
        { href: '/lavr', match: (current) => current === '/lavr' || current.startsWith('/lavr/chats/'), label: t('navigation.chat'), icon: MessageSquare },
        { href: '/lavr/people', match: (current) => current.startsWith('/lavr/people'), label: t('navigation.people'), icon: Users },
        { href: '/lavr/projects', match: (current) => current.startsWith('/lavr/projects'), label: t('navigation.projects'), icon: FolderKanban },
        {
            href: '/lavr/more',
            match: (current) =>
                current === '/lavr/more'
                || current.startsWith('/lavr/meetings')
                || current.startsWith('/lavr/commitments')
                || current.startsWith('/lavr/notifications')
                || current.startsWith('/lavr/reports')
                || current.startsWith('/lavr/briefs')
                || current.startsWith('/lavr/leadership'),
            label: t('navigation.more'),
            icon: MoreHorizontal,
        },
    ];

    return (
        <nav
            className={`lavr-bottom-nav ${force ? 'flex' : 'flex lg:hidden'}`}
            aria-label={t('navigation.aria')}
        >
            {items.map((item) => {
                const Icon = item.icon;
                const active = item.match(path);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={`flex min-h-12 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 py-1 text-xs ${
                            active ? 'font-semibold text-sky-200' : 'font-medium text-slate-400'
                        }`}
                    >
                        <Icon className="h-5 w-5" aria-hidden="true" />
                        <span className="truncate">{item.label}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
