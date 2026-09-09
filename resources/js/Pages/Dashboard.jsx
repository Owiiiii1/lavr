import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link, usePage } from '@inertiajs/react';

export default function Dashboard() {
    const { locale = 'en', owlAdmin = {} } = usePage().props;
    const brandName = owlAdmin.brand_name ?? 'LAVR';
    const text = {
        en: {
            title: 'Home',
            hello: `Good to have you back in ${brandName}.`,
            body: 'Open LAVR to talk. Admin stays here for technical setup.',
            jarvis: 'Open LAVR',
            jarvisHint: 'Personal workspace',
            calendar: 'Calendar',
            calendarHint: 'Your personal schedule',
            projects: 'Projects',
            projectsHint: 'Work containers',
            logs: 'Logs',
            logsHint: 'Recent activity and diagnostics',
            settings: 'Settings',
            settingsHint: 'AI, integrations and Telegram',
        },
        ru: {
            title: 'Главная',
            hello: `С возвращением в ${brandName}.`,
            body: 'Общение — в LAVR. Здесь остаётся техническая админка.',
            jarvis: 'Open LAVR',
            jarvisHint: 'Личный workspace',
            calendar: 'Календарь',
            calendarHint: 'Личное расписание',
            projects: 'Projects',
            projectsHint: 'Рабочие контейнеры',
            logs: 'Логи',
            logsHint: 'Активность и диагностика',
            settings: 'Настройки',
            settingsHint: 'ИИ, интеграции и Telegram',
        },
        uk: {
            title: 'Головна',
            hello: `З поверненням у ${brandName}.`,
            body: 'Спілкування — у LAVR. Тут залишається технічна адмінка.',
            jarvis: 'Open LAVR',
            jarvisHint: 'Особистий workspace',
            calendar: 'Календар',
            calendarHint: 'Особистий розклад',
            projects: 'Projects',
            projectsHint: 'Робочі контейнери',
            logs: 'Логи',
            logsHint: 'Активність і діагностика',
            settings: 'Налаштування',
            settingsHint: 'ШІ, інтеграції та Telegram',
        },
    };
    const t = text[locale] ?? text.en;

    const cards = [
        { href: route('jarvis.index'), title: t.jarvis, hint: t.jarvisHint },
        { href: route('projects.index'), title: t.projects, hint: t.projectsHint },
        { href: route('calendar.index'), title: t.calendar, hint: t.calendarHint },
        { href: route('statistics.logs'), title: t.logs, hint: t.logsHint },
        { href: route('settings.index'), title: t.settings, hint: t.settingsHint },
    ];

    return (
        <AdminLayout title={t.title}>
            <Head title={`${brandName} · ${t.title}`} />
            <div className="space-y-6">
                <div className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-5">
                    <p className="text-lg font-semibold text-slate-900">{t.hello}</p>
                    <p className="mt-1 text-sm text-slate-600">{t.body}</p>
                </div>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map((card) => (
                        <Link
                            key={card.href}
                            href={card.href}
                            className="rounded-xl border border-slate-200 bg-white p-4 transition hover:border-amber-300 hover:shadow-sm"
                        >
                            <p className="text-sm font-semibold text-slate-900">{card.title}</p>
                            <p className="mt-1 text-xs text-slate-500">{card.hint}</p>
                        </Link>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
