import LavrAppShell from '@/telegram/LavrAppShell';
import { Head, Link } from '@inertiajs/react';
import { Component } from 'react';

function Empty({ text }) {
    return <p className="text-sm text-slate-400">{text}</p>;
}

function Card({ title, children }) {
    return (
        <section className="rounded-2xl border border-white/10 bg-[var(--tg-theme-secondary-bg-color,rgba(255,255,255,0.05))] p-4">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{title}</h2>
            {children}
        </section>
    );
}

class SectionGuard extends Component {
    constructor(props) {
        super(props);
        this.state = { failed: false };
    }

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        if (this.state.failed) {
            return (
                <Card title={this.props.title}>
                    <Empty text="Этот блок сейчас недоступен." />
                </Card>
            );
        }

        return this.props.children;
    }
}

export default function Today({ today }) {
    const tasks = today?.tasks || [];
    const reminders = today?.reminders || [];
    const notifications = today?.notifications || [];
    const reports = today?.reports || [];
    const events = today?.calendar || [];

    return (
        <LavrAppShell>
            <Head title="Today" />
            <div className="jarvis-workspace px-4 pb-8 pt-5 text-slate-100 sm:px-8">
                <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{today?.brand || 'LAVR'}</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">{today?.date_label || 'Сегодня'}</h1>
                <p className="mt-2 max-w-xl text-sm leading-6 text-slate-300">{today?.summary}</p>

                <div className="mt-6">
                    <Link
                        href={today?.ask_href || '/lavr'}
                        className="inline-flex min-h-12 min-w-[12rem] items-center justify-center rounded-2xl bg-[var(--tg-theme-button-color,#0ea5e9)] px-5 text-sm font-semibold text-[var(--tg-theme-button-text-color,#fff)]"
                    >
                        Спросить LAVR
                    </Link>
                </div>

                <div className="mt-8 space-y-4">
                    <SectionGuard title="Сейчас важно">
                        <Card title="Сейчас важно">
                            {notifications.length === 0 && tasks.length === 0 && reminders.length === 0 ? (
                                <Empty text="Нет срочных пунктов." />
                            ) : (
                                <ul className="space-y-2">
                                    {notifications.slice(0, 4).map((item) => (
                                        <li key={`n-${item.id}`} className="rounded-xl bg-black/20 px-3 py-2">
                                            <p className="text-sm text-white">{item.title}</p>
                                            {item.body ? <p className="text-xs text-slate-400">{item.body}</p> : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <Link href="/lavr/notifications" className="mt-3 inline-flex min-h-11 items-center text-sm text-sky-300">
                                Все уведомления
                            </Link>
                        </Card>
                    </SectionGuard>

                    <SectionGuard title="Календарь">
                        <Card title="Календарь">
                            {today?.calendar_error ? (
                                <Empty text={today.calendar_error} />
                            ) : events.length === 0 ? (
                                <Empty text={today?.calendar_hint || 'Нет событий на сегодня.'} />
                            ) : (
                                <ul className="space-y-2">
                                    {events.map((item) => (
                                        <li key={item.id || item.title} className="rounded-xl bg-black/20 px-3 py-2">
                                            <p className="text-sm text-white">{item.title}</p>
                                            {item.when_label ? <p className="text-xs text-slate-400">{item.when_label}</p> : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </SectionGuard>

                    <SectionGuard title="Задачи и напоминания">
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
                    </SectionGuard>

                    <SectionGuard title="Отчеты">
                        <Card title="Отчеты">
                            {reports.length === 0 ? (
                                <Empty text="Нет активных отчетов." />
                            ) : (
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
                            )}
                            <Link href="/lavr/reports" className="mt-3 inline-flex min-h-11 items-center text-sm text-sky-300">
                                Все отчеты
                            </Link>
                        </Card>
                    </SectionGuard>
                </div>
            </div>
        </LavrAppShell>
    );
}
