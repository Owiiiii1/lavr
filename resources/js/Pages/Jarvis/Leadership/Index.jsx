import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function LeadershipIndex({ reviews, filters, projects = [], people = [], meetings = [] }) {
    const { t } = useTranslation();
    const rows = reviews?.data || reviews || [];
    const form = useForm({
        review_type: 'owner',
        period: '30',
        from: '',
        to: '',
        project_id: '',
        person_id: '',
        meeting_id: '',
    });

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            '/lavr/leadership',
            {
                type: event.target.type.value,
                status: event.target.status.value,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <LavrAppShell>
            <Head title={t('leadership.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">{t('common.lavr')}</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">{t('leadership.title')}</h1>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-400">{t('leadership.intro')}</p>

                <form
                    className="mt-6 space-y-3 rounded-2xl border border-white/10 bg-white/5 p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/lavr/leadership/generate');
                    }}
                >
                    <p className="text-xs uppercase tracking-[0.16em] text-slate-400">{t('leadership.generate')}</p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <select
                            value={form.data.review_type}
                            onChange={(event) => form.setData('review_type', event.target.value)}
                            className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm"
                        >
                            <option value="owner">{t('leadership.scope_owner')}</option>
                            <option value="team">{t('leadership.scope_team')}</option>
                            <option value="project">{t('leadership.scope_project')}</option>
                            <option value="person">{t('leadership.scope_person')}</option>
                            <option value="meeting">{t('leadership.scope_meeting')}</option>
                        </select>
                        <select
                            value={form.data.period}
                            onChange={(event) => form.setData('period', event.target.value)}
                            className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm"
                        >
                            <option value="7">{t('leadership.period7')}</option>
                            <option value="30">{t('leadership.period30')}</option>
                            <option value="custom">{t('leadership.periodCustom')}</option>
                        </select>
                    </div>
                    {form.data.period === 'custom' ? (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <input type="date" value={form.data.from} onChange={(event) => form.setData('from', event.target.value)} className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm" />
                            <input type="date" value={form.data.to} onChange={(event) => form.setData('to', event.target.value)} className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm" />
                        </div>
                    ) : null}
                    {form.data.review_type === 'project' ? (
                        <select value={form.data.project_id} onChange={(event) => form.setData('project_id', event.target.value)} className="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm">
                            <option value="">{t('leadership.chooseProject')}</option>
                            {projects.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                    ) : null}
                    {form.data.review_type === 'person' ? (
                        <select value={form.data.person_id} onChange={(event) => form.setData('person_id', event.target.value)} className="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm">
                            <option value="">{t('leadership.choosePerson')}</option>
                            {people.map((item) => <option key={item.id} value={item.id}>{item.display_name}</option>)}
                        </select>
                    ) : null}
                    {form.data.review_type === 'meeting' ? (
                        <select value={form.data.meeting_id} onChange={(event) => form.setData('meeting_id', event.target.value)} className="w-full rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm">
                            <option value="">{t('leadership.chooseMeeting')}</option>
                            {meetings.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                        </select>
                    ) : null}
                    <button type="submit" className="inline-flex min-h-11 items-center rounded-2xl bg-sky-500/90 px-4 text-sm font-semibold text-white">
                        {t('leadership.generate')}
                    </button>
                </form>

                <form className="mt-6 grid gap-2 sm:grid-cols-3" onSubmit={applyFilters}>
                    <select name="type" defaultValue={filters?.type || ''} className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm">
                        <option value="">{t('leadership.allScopes')}</option>
                        <option value="owner">{t('leadership.scope_owner')}</option>
                        <option value="team">{t('leadership.scope_team')}</option>
                        <option value="project">{t('leadership.scope_project')}</option>
                        <option value="person">{t('leadership.scope_person')}</option>
                        <option value="meeting">{t('leadership.scope_meeting')}</option>
                    </select>
                    <select name="status" defaultValue={filters?.status || ''} className="rounded-xl border border-white/10 bg-black/30 px-3 py-2 text-sm">
                        <option value="">{t('leadership.allStatuses')}</option>
                        <option value="ready">{t('leadership.status_ready')}</option>
                        <option value="partial">{t('leadership.status_partial')}</option>
                        <option value="insufficient_data">{t('leadership.status_insufficient')}</option>
                    </select>
                    <button type="submit" className="rounded-xl border border-white/10 px-3 text-sm">{t('leadership.filter')}</button>
                </form>

                {rows.length === 0 ? (
                    <p className="mt-8 text-sm text-slate-400">{t('leadership.empty')}</p>
                ) : (
                    <ul className="mt-6 space-y-2">
                        {rows.map((item) => (
                            <li key={item.id}>
                                <Link href={item.href || `/lavr/leadership/${item.id}`} className="block rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                                    <p className="text-sm font-medium text-white">{item.period_start} → {item.period_end} · {item.review_type}</p>
                                    <p className="mt-1 text-xs text-slate-400">{item.status}{item.top_findings?.[0]?.title ? ` · ${item.top_findings[0].title}` : ''}</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </LavrAppShell>
    );
}
