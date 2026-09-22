import { Link, router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Activity,
    CalendarDays,
    ChartColumn,
    FileText,
    FolderKanban,
    Globe,
    Home,
    LogOut,
    MessagesSquare,
    Sparkles,
    Settings,
    UserCircle2,
    Users,
    Building2,
    Video,
    ListChecks,
    Menu,
} from 'lucide-react';
import { useState } from 'react';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/Components/ui/sheet';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

const primaryNavItems = [
    { route: 'dashboard', icon: Home },
    { route: 'projects.index', icon: FolderKanban, activePattern: 'projects.*' },
    { route: 'people.index', icon: Users, activePattern: 'people.*' },
    { route: 'organizations.index', icon: Building2, activePattern: 'organizations.*' },
    { route: 'meetings.index', icon: Video, activePattern: 'meetings.*' },
    { route: 'commitments.index', icon: ListChecks, activePattern: 'commitments.*' },
    { route: 'executive-briefs.index', icon: FileText, activePattern: 'executive-briefs.*' },
    { route: 'leadership-reviews.index', icon: ChartColumn, activePattern: 'leadership-reviews.*' },
    { route: 'automation-runs.index', icon: Activity, activePattern: 'automation-runs.*' },
    { route: 'telegram-groups.index', icon: MessagesSquare, activePattern: 'telegram-groups.*' },
    { route: 'calendar.index', icon: CalendarDays },
];

const languageLabels = {
    uk: 'Українська',
    en: 'English',
    ru: 'Русский',
};

function navLabel(routeName, t) {
    if (routeName === 'dashboard') return t.home;
    if (routeName === 'projects.index') return t.projects;
    if (routeName === 'people.index') return t.people;
    if (routeName === 'organizations.index') return t.organizations;
    if (routeName === 'meetings.index') return t.meetings;
    if (routeName === 'commitments.index') return t.commitments;
    if (routeName === 'executive-briefs.index') return t.executiveBriefs;
    if (routeName === 'leadership-reviews.index') return t.leadershipReviews;
    if (routeName === 'automation-runs.index') return t.automationRuns;
    if (routeName === 'telegram-groups.index') return t.telegramGroups;
    if (routeName === 'calendar.index') return t.calendar;
    return routeName;
}

export default function AdminLayout({ title, children }) {
    const { auth, locale = 'en', owlAdmin = {} } = usePage().props;
    const user = auth?.user;
    const brandName = owlAdmin?.brand_name ?? 'LAVR';
    const logoPath = owlAdmin?.logo_path ?? '/images/company-logo.svg';
    const brandTagline = owlAdmin?.tagline ?? 'Workspace';

    const ai = owlAdmin?.ai ?? {};
    const aiConnected = !!ai.connected;

    const telegram = owlAdmin?.telegram ?? {};
    const telegramStatus = telegram.status;
    let telegramBadgeClass = 'bg-red-100 text-red-700';

    if (telegramStatus === 'connected') {
        telegramBadgeClass = 'bg-emerald-100 text-emerald-700';
    } else if (telegramStatus === 'incomplete') {
        telegramBadgeClass = 'bg-amber-100 text-amber-800';
    }

    const [statisticsOpen, setStatisticsOpen] = useState(route().current('statistics.*'));
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [mobileStatisticsOpen, setMobileStatisticsOpen] = useState(
        route().current('statistics.*'),
    );
    const [localeSwitching, setLocaleSwitching] = useState(false);

    const uiText = {
        en: {
            home: 'Home',
            projects: 'Projects',
            people: 'People',
            organizations: 'Organizations',
            meetings: 'Meetings',
            commitments: 'Commitments',
            executiveBriefs: 'Executive Briefs',
            leadershipReviews: 'Leadership Reviews',
            automationRuns: 'Automation Runs',
            telegramGroups: 'Telegram Groups',
            calendar: 'Calendar',
            settings: 'Settings',
            logout: 'Logout',
            statistics: 'Statistics',
            logs: 'Logs',
            adminPanel: 'Admin Panel',
            openWorkspace: 'Open LAVR',
            profile: 'Profile',
            language: 'Language',
            aiOff: 'AI: not connected',
            aiOn: 'AI: Owner Conversation — {provider} / {model}',
            botOff: 'Bot: not connected',
            botOn: 'Bot: connected — @{name}',
            botIncomplete: 'Bot: incomplete',
        },
        ru: {
            home: 'Главная',
            projects: 'Проекты',
            people: 'Люди',
            organizations: 'Организации',
            meetings: 'Встречи',
            commitments: 'Обязательства',
            executiveBriefs: 'Сводки',
            leadershipReviews: 'Обзоры руководства',
            automationRuns: 'Автоматизации',
            telegramGroups: 'Группы Telegram',
            calendar: 'Календарь',
            settings: 'Настройки',
            logout: 'Выход',
            statistics: 'Статистика',
            logs: 'Логи',
            adminPanel: 'Админ-панель',
            openWorkspace: 'Открыть LAVR',
            aiOff: 'AI: не подключено',
            aiOn: 'AI: разговор владельца — {provider} / {model}',
            botOff: 'Бот: не подключён',
            botOn: 'Бот: подключён — @{name}',
            botIncomplete: 'Бот: не завершено',
            profile: 'Профиль',
            language: 'Язык',
        },
        uk: {
            home: 'Головна',
            projects: 'Проєкти',
            people: 'Люди',
            organizations: 'Організації',
            meetings: 'Зустрічі',
            commitments: 'Зобов’язання',
            executiveBriefs: 'Зведення',
            leadershipReviews: 'Огляди керівництва',
            automationRuns: 'Автоматизації',
            telegramGroups: 'Групи Telegram',
            calendar: 'Календар',
            settings: 'Налаштування',
            logout: 'Вийти',
            statistics: 'Статистика',
            logs: 'Логи',
            adminPanel: 'Адмін-панель',
            openWorkspace: 'Відкрити LAVR',
            aiOff: 'AI: не підключено',
            aiOn: 'AI: розмова власника — {provider} / {model}',
            botOff: 'Бот: не підключено',
            botOn: 'Бот: підключено — @{name}',
            botIncomplete: 'Бот: не завершено',
            profile: 'Профіль',
            language: 'Мова',
        },
    };

    const t = uiText[locale] ?? uiText.en;
    const currentLanguageLabel = languageLabels[locale] ?? languageLabels.en;
    const aiBadgeText = aiConnected
        ? t.aiOn
            .replace('{provider}', ai.provider_label ?? ai.provider ?? '')
            .replace('{model}', ai.model ?? '')
        : t.aiOff;
    const telegramBadgeText = telegramStatus === 'connected'
        ? t.botOn.replace('{name}', telegram.bot_username ?? '')
        : telegramStatus === 'incomplete'
            ? t.botIncomplete
            : t.botOff;

    const settingsActive =
        route().current('settings.*')
        || route().current('ai-settings.*')
        || route().current('app-settings.*');

    const switchLocale = (nextLocale) => {
        if (!nextLocale || nextLocale === locale || localeSwitching) {
            return;
        }

        setLocaleSwitching(true);
        router.post(
            route('settings.language.update'),
            { locale: nextLocale },
            {
                preserveScroll: true,
                onFinish: () => setLocaleSwitching(false),
            },
        );
    };

    const renderPrimaryNav = (mobile = false) =>
        primaryNavItems.map(({ route: routeName, icon: Icon, activePattern }) => {
            const active = activePattern
                ? route().current(activePattern)
                : route().current(routeName);

            return (
                <Link
                    key={`${mobile ? 'mobile-' : ''}${routeName}`}
                    href={route(routeName)}
                    onClick={() => mobile && setMobileMenuOpen(false)}
                    className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                        active
                            ? 'border-l-2 border-amber-400 bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-white'
                    }`}
                >
                    <Icon className="h-4 w-4" />
                    <span>{navLabel(routeName, t)}</span>
                </Link>
            );
        });

    const renderStatistics = (mobile = false) => {
        const open = mobile ? mobileStatisticsOpen : statisticsOpen;
        const setOpen = mobile ? setMobileStatisticsOpen : setStatisticsOpen;

        return (
            <div>
                <button
                    type="button"
                    onClick={() => setOpen((prev) => !prev)}
                    className={`flex w-full items-center justify-between rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                        route().current('statistics.*')
                            ? 'border-l-2 border-amber-400 bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-white'
                    }`}
                >
                    <span className="flex items-center gap-3">
                        <ChartColumn className="h-4 w-4" />
                        <span>{t.statistics}</span>
                    </span>
                    <ChevronDown
                        className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`}
                    />
                </button>

                {open && (
                    <div className="mt-1 space-y-1 pl-4">
                        <Link
                            href={route('statistics.logs')}
                            onClick={() => mobile && setMobileMenuOpen(false)}
                            className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${
                                route().current('statistics.logs')
                                    ? 'bg-white/10 text-white'
                                    : 'text-slate-400 hover:bg-white/5 hover:text-white'
                            }`}
                        >
                            <FileText className="h-4 w-4" />
                            <span>{t.logs}</span>
                        </Link>
                    </div>
                )}
            </div>
        );
    };

    const renderSettingsLink = (mobile = false) => (
        <Link
            href={route('settings.index')}
            onClick={() => mobile && setMobileMenuOpen(false)}
            className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                settingsActive
                    ? 'border-l-2 border-amber-400 bg-white/10 text-white'
                    : 'text-slate-400 hover:bg-white/5 hover:text-white'
            }`}
        >
            <Settings className="h-4 w-4" />
            <span>{t.settings}</span>
        </Link>
    );

    const renderJarvisLink = (mobile = false) => (
        <Link
            href={route('jarvis.index')}
            onClick={() => mobile && setMobileMenuOpen(false)}
            className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-amber-200 hover:bg-white/5 hover:text-white"
        >
            <Sparkles className="h-4 w-4" />
            <span>{t.openWorkspace}</span>
        </Link>
    );

    const renderBottomNav = (mobile = false) => (
        <div className="mt-auto space-y-1.5">
            <div className="my-3 border-t border-white/10" />
            {renderJarvisLink(mobile)}
            {renderStatistics(mobile)}
            <div className="my-3 border-t border-white/10" />
            {renderSettingsLink(mobile)}
            <div className="mt-3 border-t border-white/10 pt-4">
                <p className="px-3 text-xs text-slate-500">
                    Powered by{' '}
                    <a
                        href="https://owlsolutions.net"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-medium text-slate-300 transition hover:text-white"
                    >
                        OwlSolutions
                    </a>
                </p>
            </div>
        </div>
    );

    return (
        <div className="h-screen overflow-hidden bg-[#F4EFE4] text-slate-900">
            <div className="flex h-screen">
                <aside className="fixed inset-y-0 left-0 hidden w-72 flex-col bg-[#0B1220] text-white shadow-2xl lg:flex">
                    <div className="px-6 py-6">
                        <div className="flex items-center gap-3">
                            <img
                                src={logoPath}
                                alt={brandName}
                                className="h-10 w-10 rounded-2xl"
                            />
                            <div>
                                <p className="text-base font-semibold text-white">{brandName}</p>
                                <p className="text-[11px] uppercase tracking-[0.18em] text-amber-300/80">
                                    {brandTagline}
                                </p>
                            </div>
                        </div>
                    </div>

                    <nav className="flex h-full flex-1 flex-col px-4 pb-6">
                        <div className="space-y-1.5">
                            {renderPrimaryNav(false)}
                        </div>

                        {renderBottomNav(false)}
                    </nav>
                </aside>

                <Sheet open={mobileMenuOpen} onOpenChange={setMobileMenuOpen}>
                    <SheetContent
                        side="left"
                        className="w-80 border-r-0 bg-[#0B1220] p-0 text-white data-[side=left]:w-80 data-[side=left]:sm:max-w-80"
                    >
                        <SheetHeader className="border-b border-white/10 px-6 py-6 text-left">
                            <SheetTitle className="text-base font-semibold text-white">
                                {brandName}
                            </SheetTitle>
                        </SheetHeader>

                        <nav className="flex h-full flex-1 flex-col px-4 pb-6 pt-4">
                            <div className="space-y-1.5">
                                {renderPrimaryNav(true)}
                            </div>

                            {renderBottomNav(true)}
                        </nav>
                    </SheetContent>
                </Sheet>

                <div className="flex min-w-0 flex-1 flex-col overflow-hidden lg:pl-72">
                    <header className="sticky top-0 z-20 border-b border-[#E6DCC8] bg-[#FBF8F1]/90 backdrop-blur-xl">
                        <div className="flex h-16 items-center justify-between px-4 sm:px-10">
                            <div className="flex items-center gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    className="h-9 w-9 lg:hidden"
                                    onClick={() => setMobileMenuOpen(true)}
                                    aria-label="Open menu"
                                >
                                    <Menu className="h-4 w-4" />
                                </Button>
                                <div>
                                    <div className="text-sm font-semibold tracking-wide text-slate-800">
                                        {brandName}
                                    </div>
                                    <div className="hidden text-[11px] uppercase tracking-[0.16em] text-amber-800/70 sm:block">
                                        {t.adminPanel}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <Link
                                    href={route('jarvis.index')}
                                    className="hidden rounded-full border border-amber-300/70 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-50 sm:inline-flex"
                                >
                                    {t.openWorkspace}
                                </Link>
                                <span
                                    className={`hidden max-w-[280px] truncate rounded-full px-3 py-1 text-xs font-semibold lg:inline-flex ${
                                        aiConnected
                                            ? 'bg-emerald-100 text-emerald-700'
                                            : 'bg-red-100 text-red-700'
                                    }`}
                                    title={aiBadgeText}
                                >
                                    {aiBadgeText}
                                </span>

                                <span
                                    className={`hidden max-w-[280px] truncate rounded-full px-3 py-1 text-xs font-semibold md:inline-flex ${telegramBadgeClass}`}
                                    title={telegramBadgeText}
                                >
                                    {telegramBadgeText}
                                </span>

                                <div className="relative">
                                    <label htmlFor="admin-header-language" className="sr-only">
                                        {t.language}
                                    </label>
                                    <div className="inline-flex h-9 items-center gap-2 rounded-lg border border-[#E6DCC8] bg-white px-3 pr-8 text-sm font-medium text-slate-700 shadow-sm">
                                        <Globe className="h-4 w-4 text-amber-700" />
                                        <span className="hidden sm:inline">{currentLanguageLabel}</span>
                                        <ChevronDown className="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                                    </div>
                                    <select
                                        id="admin-header-language"
                                        value={locale}
                                        disabled={localeSwitching}
                                        onChange={(e) => switchLocale(e.target.value)}
                                        className="absolute inset-0 h-9 w-full cursor-pointer opacity-0 disabled:cursor-wait"
                                    >
                                        <option value="uk">Українська</option>
                                        <option value="en">English</option>
                                        <option value="ru">Русский</option>
                                    </select>
                                </div>

                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            className="inline-flex h-9 w-9 items-center justify-center overflow-hidden rounded-full border border-[#E6DCC8] bg-white text-slate-700 outline-none transition hover:bg-amber-50 focus-visible:ring-2 focus-visible:ring-amber-200"
                                            aria-label={t.profile}
                                        >
                                            {user?.avatar_path ? (
                                                <img
                                                    src={`/storage/${user.avatar_path}`}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <UserCircle2 className="h-5 w-5" />
                                            )}
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="min-w-40">
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href={route('profile.edit')}
                                                className="cursor-pointer"
                                            >
                                                <UserCircle2 className="h-4 w-4" />
                                                <span>{t.profile}</span>
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                                className="w-full cursor-pointer"
                                            >
                                                <LogOut className="h-4 w-4" />
                                                <span>{t.logout}</span>
                                            </Link>
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </header>

                    <main className="flex-1 overflow-y-auto p-4 sm:p-10">
                        <div className="rounded-2xl border border-[#E6DCC8] bg-white p-6 shadow-[0_16px_40px_-28px_rgba(28,25,23,0.35)]">
                            <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
                            <div className="mt-4">{children}</div>
                        </div>
                    </main>
                </div>
            </div>
        </div>
    );
}
