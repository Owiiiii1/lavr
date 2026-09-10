import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router } from '@inertiajs/react';
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
                    <Empty text={this.props.fallback} />
                </Card>
            );
        }

        return this.props.children;
    }
}

function ItemList({ items }) {
    return (
        <ul className="space-y-2">
            {items.map((item, index) => (
                <li key={item.dedupe_key || item.id || `${item.title}-${index}`}>
                    {item.deep_link || item.href ? (
                        <Link href={item.deep_link || item.href} className="block rounded-xl bg-black/20 px-3 py-2">
                            <p className="text-sm text-white">{item.title}</p>
                            {item.summary ? <p className="text-xs text-slate-400">{item.summary}</p> : null}
                        </Link>
                    ) : (
                        <div className="rounded-xl bg-black/20 px-3 py-2">
                            <p className="text-sm text-white">{item.title}</p>
                            {item.summary || item.when_label ? (
                                <p className="text-xs text-slate-400">{item.summary || item.when_label}</p>
                            ) : null}
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function Today({ today }) {
    const { t } = useTranslation();
    const attention = today?.attention || [];
    const todayItems = today?.today_items || [];
    const events = today?.calendar || [];
    const fallback = t('today.sectionUnavailable');
    const commitments = today?.commitments || [];

    return (
        <LavrAppShell>
            <Head title={t('today.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-5 text-slate-100 sm:px-8">
                <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{today?.brand || t('common.lavr')}</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">{today?.date_label || t('today.title')}</h1>
                <p className="mt-2 max-w-xl text-sm leading-6 text-slate-300">{today?.summary}</p>

                <div className="mt-6 flex flex-wrap gap-3">
                    <Link
                        href={today?.ask_href || '/lavr'}
                        className="inline-flex min-h-12 min-w-[12rem] items-center justify-center rounded-2xl bg-[var(--tg-theme-button-color,#0ea5e9)] px-5 text-sm font-semibold text-[var(--tg-theme-button-text-color,#fff)]"
                    >
                        {t('today.ask')}
                    </Link>
                    <button
                        type="button"
                        onClick={() => router.post('/lavr/briefs/generate')}
                        className="inline-flex min-h-12 items-center rounded-2xl border border-white/15 px-4 text-sm text-slate-200"
                    >
                        {t('brief.generate')}
                    </button>
                </div>

                <div className="mt-8 space-y-4">
                    <SectionGuard title={t('brief.title')} fallback={fallback}>
                        <Card title={t('brief.title')}>
                            <p className="text-sm text-slate-200">{today?.summary}</p>
                            <Link href={today?.brief_href || '/lavr/briefs'} className="mt-3 inline-flex min-h-11 items-center text-sm text-sky-300">
                                {t('brief.open')}
                            </Link>
                        </Card>
                    </SectionGuard>

                    <SectionGuard title={t('today.now')} fallback={fallback}>
                        <Card title={t('today.now')}>
                            {attention.length === 0 ? <Empty text={t('today.noUrgent')} /> : <ItemList items={attention} />}
                        </Card>
                    </SectionGuard>

                    <SectionGuard title={t('today.calendar')} fallback={fallback}>
                        <Card title={t('today.calendar')}>
                            {today?.calendar_error ? (
                                <Empty text={today.calendar_error} />
                            ) : todayItems.length === 0 && events.length === 0 ? (
                                <Empty text={today?.calendar_hint || t('today.noEvents')} />
                            ) : (
                                <ItemList items={todayItems.length > 0 ? todayItems : events} />
                            )}
                        </Card>
                    </SectionGuard>

                    <SectionGuard title={t('today.commitments')} fallback={fallback}>
                        <Card title={t('today.commitments')}>
                            {commitments.length === 0 ? (
                                <Empty text={t('today.noCommitments')} />
                            ) : (
                                <ul className="space-y-2">
                                    {commitments.map((item) => (
                                        <li key={`c-${item.id || item.title}`}>
                                            <Link href={item.href || item.deep_link || `/lavr/commitments/${item.id}`} className="block rounded-xl bg-black/20 px-3 py-2">
                                                <p className="text-sm text-white">{item.title}</p>
                                                <p className="text-xs text-slate-400">
                                                    {item.status || item.summary || ''}
                                                    {item.person?.display_name ? ` · ${item.person.display_name}` : ''}
                                                </p>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <Link href="/lavr/commitments" className="mt-3 inline-flex min-h-11 items-center text-sm text-sky-300">
                                {t('today.allCommitments')}
                            </Link>
                        </Card>
                    </SectionGuard>
                </div>
            </div>
        </LavrAppShell>
    );
}
