import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

function FindingList({ title, rows }) {
    if (!rows || rows.length === 0) {
        return null;
    }

    return (
        <section className="rounded-2xl border border-white/10 bg-white/5 p-4">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{title}</h2>
            <ul className="space-y-3">
                {rows.map((item, index) => (
                    <li key={item.id || `${item.title}-${index}`} className="rounded-xl bg-black/20 px-3 py-2">
                        <p className="text-sm text-white">{item.title || item.observation}</p>
                        {item.observation && item.observation !== item.title ? (
                            <p className="mt-1 text-xs text-slate-400">{item.observation}</p>
                        ) : null}
                        {item.recommendation ? <p className="mt-1 text-xs text-sky-200">{item.recommendation}</p> : null}
                        {(item.evidence_refs || []).length > 0 ? (
                            <ul className="mt-2 flex flex-wrap gap-2">
                                {item.evidence_refs.map((ref) => (
                                    <li key={`${ref.type}-${ref.id}`}>
                                        <Link href={ref.href || '#'} className="text-xs text-sky-300">
                                            {ref.label || `${ref.type} ${ref.id}`}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function MetricGrid({ metrics, t }) {
    const items = [
        ['commitments_total', t('leadership.metric_commitments')],
        ['commitments_overdue', t('leadership.metric_overdue')],
        ['owner_coverage_pct', t('leadership.metric_owner')],
        ['deadline_coverage_pct', t('leadership.metric_deadline')],
        ['meeting_action_items', t('leadership.metric_actions')],
        ['likely_done_unconfirmed', t('leadership.metric_likely')],
    ];

    return (
        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {items.map(([key, label]) => (
                <div key={key} className="rounded-xl bg-black/20 px-3 py-2">
                    <dt className="text-[11px] uppercase tracking-[0.12em] text-slate-500">{label}</dt>
                    <dd className="mt-1 text-sm text-white">{metrics?.[key] ?? '—'}</dd>
                </div>
            ))}
        </dl>
    );
}

export default function LeadershipShow({ review }) {
    const { t } = useTranslation();
    const sections = review?.sections || {};
    const snapshot = review?.source_snapshot || {};

    return (
        <LavrAppShell>
            <Head title={t('leadership.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/leadership" className="text-sm text-sky-300">{t('leadership.back')}</Link>
                <h1 className="mt-3 text-2xl font-semibold text-white">{t('leadership.title')}</h1>
                <p className="mt-2 max-w-xl text-sm leading-6 text-slate-300">{review?.summary}</p>
                <p className="mt-1 text-xs text-slate-500">
                    {review?.period_start} → {review?.period_end} · {review?.review_type} · {review?.status}
                </p>
                {snapshot.data_through ? (
                    <p className="mt-1 text-xs text-slate-500">{t('leadership.dataThrough')}: {snapshot.data_through}</p>
                ) : null}

                <div className="mt-6 space-y-4">
                    <section className="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{t('leadership.overview')}</h2>
                        <MetricGrid metrics={review?.metrics} t={t} />
                    </section>
                    <FindingList title={t('leadership.strengths')} rows={sections.strengths} />
                    <FindingList title={t('leadership.attention')} rows={sections.attention} />
                    <FindingList title={t('leadership.meetings')} rows={sections.meetings} />
                    <FindingList title={t('leadership.commitments')} rows={sections.commitments} />
                    <FindingList title={t('leadership.delegation')} rows={sections.delegation} />
                    <FindingList title={t('leadership.followUp')} rows={sections.follow_up} />
                    <FindingList title={t('leadership.bottlenecks')} rows={sections.bottlenecks} />
                    {(review?.trends || []).length > 0 ? (
                        <FindingList title={t('leadership.trends')} rows={review.trends} />
                    ) : null}
                </div>
            </div>
        </LavrAppShell>
    );
}
