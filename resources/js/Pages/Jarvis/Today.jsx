import LavrAppShell from '@/telegram/LavrAppShell';
import { Head, Link } from '@inertiajs/react';

function Empty({ text }) {
    return <p className="text-sm text-slate-400">{text}</p>;
}

function Card({ title, children }) {
    return (
        <section className="rounded-2xl border border-white/10 bg-white/5 p-4">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{title}</h2>
            {children}
        </section>
    );
}

export default function Today({ today }) {
    const tasks = today?.tasks || [];
    const reminders = today?.reminders || [];
    const notifications = today?.notifications || [];
    const reports = today?.reports || [];

    return (
        <LavrAppShell>
            <Head title="Today" />
            <div className="jarvis-workspace min-h-[100dvh] px-4 pb-6 pt-5 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">Today</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">{today?.date_label || 'Сегодня'}</h1>
                <p className="mt-2 max-w-xl text-sm leading-6 text-slate-300">{today?.summary}</p>

                <div className="mt-6">
                    <Link
                        href={today?.ask_href || '/lavr'}
                        className="inline-flex min-h-12 items-center justify-center rounded-2xl bg-sky-500 px-5 text-sm font-semibold text-white"
                    >
                        Спросить LAVR
                    </Link>
                </div>

                <div className="mt-8 space-y-4">
                    <Card title="Календарь">
                        <Empty text={today?.calendar_hint || 'Нет событий на сегодня.'} />
                    </Card>

                    <Card title="Задачи и напоминания">
                        {tasks.length === 0 && reminders.length === 0 ? (
                            <Empty text="Нет задач и напоминаний на сегодня." />
                        ) : (
                            <ul className="space-y-2">
                                {tasks.map((item) => (
                                    <li key={`task-${item.id}`} className="rounded-xl bg-black/20 px-3 py-2">
                                        <p className="text-sm text-white">{item.title}</p>
                                        {item.due_label ? <p className="text-xs text-slate-400">{item.due_label}</p> : null}
                                    </li>
                                ))}
                                {reminders.map((item) => (
                                    <li key={`reminder-${item.id}`} className="rounded-xl bg-black/20 px-3 py-2">
                                        <p className="text-sm text-white">{item.text || item.title}</p>
                                        {item.schedule_label ? <p className="text-xs text-slate-400">{item.schedule_label}</p> : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card title="Уведомления">
                        {notifications.length === 0 ? (
                            <Empty text="Нет важных уведомлений." />
                        ) : (
                            <ul className="space-y-2">
                                {notifications.map((item) => (
                                    <li key={item.id} className="rounded-xl bg-black/20 px-3 py-2">
                                        <p className="text-sm text-white">{item.title}</p>
                                        {item.body ? <p className="text-xs text-slate-400">{item.body}</p> : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href="/lavr?notifications=1" className="mt-3 inline-block text-sm text-sky-300">
                            Все уведомления
                        </Link>
                    </Card>

                    {reports.length > 0 ? (
                        <Card title="Отчеты">
                            <ul className="space-y-2">
                                {reports.map((item) => (
                                    <li key={item.id} className="rounded-xl bg-black/20 px-3 py-2 text-sm text-white">
                                        {item.name}
                                        {item.schedule_label ? (
                                            <span className="mt-1 block text-xs text-slate-400">{item.schedule_label}</span>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ) : null}
                </div>
            </div>
        </LavrAppShell>
    );
}
