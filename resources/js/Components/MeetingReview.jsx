import { useTranslation } from '@/locales/useTranslation';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

const STATUS_CLASS = {
    good: 'bg-emerald-50 text-emerald-800',
    needs_attention: 'bg-amber-50 text-amber-900',
    insufficient_data: 'bg-slate-100 text-slate-600',
};

const DARK_STATUS_CLASS = {
    good: 'bg-emerald-500/15 text-emerald-200',
    needs_attention: 'bg-amber-500/15 text-amber-100',
    insufficient_data: 'bg-white/10 text-slate-300',
};

function durationLabel(meeting) {
    if (!meeting.started_at || !meeting.ended_at) {
        return null;
    }

    const minutes = Math.round((new Date(meeting.ended_at) - new Date(meeting.started_at)) / 60000);

    if (!Number.isFinite(minutes) || minutes <= 0) {
        return null;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
}

function Evidence({ item, dark }) {
    const { t } = useTranslation();
    const evidence = item.evidence || item;
    const excerpt = evidence.excerpt || null;

    if (!excerpt) {
        return null;
    }

    return (
        <details className="mt-2">
            <summary className={`cursor-pointer text-xs ${dark ? 'text-sky-300' : 'text-indigo-700'}`}>{t('meetings.review.why')}</summary>
            <p className={`mt-1 text-xs ${dark ? 'text-slate-300' : 'text-slate-500'}`}>
                {evidence.timestamp ? `${evidence.timestamp} · ` : ''}
                {evidence.speaker ? `${evidence.speaker}: ` : ''}
                “{excerpt}”
            </p>
        </details>
    );
}

function CappedList({ title, items, render, empty, dark }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const visible = open ? items : items.slice(0, 5);

    return (
        <section className={dark ? 'rounded-2xl border border-white/10 bg-white/5 p-4' : 'rounded-xl border border-slate-200 bg-white p-4'}>
            <div className="flex items-center justify-between gap-3">
                <h3 className={`text-sm font-semibold ${dark ? 'text-white' : 'text-slate-900'}`}>{title}</h3>
                <span className={`text-xs ${dark ? 'text-slate-400' : 'text-slate-500'}`}>{items.length}</span>
            </div>
            {items.length === 0 ? (
                <p className={`mt-2 text-sm ${dark ? 'text-slate-400' : 'text-slate-500'}`}>{empty}</p>
            ) : (
                <ul className="mt-3 space-y-2">{visible.map(render)}</ul>
            )}
            {items.length > 5 ? (
                <button type="button" className={`mt-3 text-xs ${dark ? 'text-sky-300' : 'text-indigo-700'}`} onClick={() => setOpen((value) => !value)}>
                    {open ? t('meetings.review.showLess') : t('meetings.review.showAll')}
                </button>
            ) : null}
        </section>
    );
}

export default function MeetingReview({ meeting, people = [], commitmentItems = [], tone = 'light', routeName = 'meetings' }) {
    const { t } = useTranslation();
    const dark = tone === 'dark';
    const result = meeting.analysis?.result || {};
    const review = result.review;
    const metrics = review?.metrics || {};
    const statusClass = dark ? DARK_STATUS_CLASS : STATUS_CLASS;
    const card = dark ? 'rounded-2xl border border-white/10 bg-white/5 p-4' : 'rounded-xl border border-slate-200 bg-white p-4';
    const muted = dark ? 'text-slate-400' : 'text-slate-500';
    const heading = dark ? 'text-white' : 'text-slate-900';
    const duration = durationLabel(meeting);
    const promoted = (commitmentItems || []).filter((item) => item.promoted).length;

    const assign = (personId, skip = false) => {
        router.post(route(`${routeName}.review-subject`, meeting.id), {
            review_subject_person_id: personId || null,
            skip_leadership_review: skip,
        }, { preserveScroll: true });
    };

    const cards = [
        [t('meetings.review.actions'), metrics.actions_total ?? (result.action_items || []).length],
        [t('meetings.review.commitments'), metrics.commitments_total ?? 0],
        [t('meetings.review.withoutDeadline'), metrics.actions_without_deadline ?? 0],
        [t('meetings.review.withoutOwner'), metrics.actions_without_owner ?? 0],
        [t('meetings.review.openItems'), metrics.open_questions_end ?? (review?.open_questions || []).length],
        [t('meetings.review.topRisks'), (review?.risks || []).length],
    ];

    return (
        <div className="space-y-4">
            <section className={card}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className={`text-lg font-semibold ${heading}`}>{meeting.title}</h2>
                        <p className={`mt-1 text-sm ${muted}`}>
                            {meeting.started_at ? meeting.started_at.slice(0, 16).replace('T', ' ') : '—'}
                            {duration ? ` · ${duration}` : ''}
                            {` · ${meeting.participants_count ?? (meeting.participants || []).length}`}
                        </p>
                    </div>
                    <label className={`text-sm ${dark ? 'text-slate-200' : 'text-slate-700'}`}>
                        {t('meetings.review.reviewing')}
                        <select
                            className={`mt-1 block min-h-10 w-full rounded-lg border px-3 py-2 text-sm ${dark ? 'border-white/10 bg-slate-950 text-white' : 'border-slate-300 bg-white'}`}
                            value={meeting.review_subject?.id || ''}
                            onChange={(event) => assign(event.target.value, event.target.value === '')}
                        >
                            <option value="">{t('meetings.review.selectParticipant')}</option>
                            {people.map((person) => (
                                <option key={person.id} value={person.id}>{person.display_name}</option>
                            ))}
                        </select>
                    </label>
                </div>
            </section>

            <section className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
                {cards.map(([label, value]) => (
                    <div key={label} className={card}>
                        <p className={`text-xs ${muted}`}>{label}</p>
                        <p className={`mt-1 text-2xl font-semibold ${heading}`}>{value}</p>
                    </div>
                ))}
            </section>

            <section className={card}>
                <h2 className={`text-sm font-semibold ${heading}`}>{t('meetings.review.insight')}</h2>
                <p className={`mt-2 text-sm leading-6 ${dark ? 'text-slate-200' : 'text-slate-700'}`}>
                    {review?.main_insight || result.summary?.executive || meeting.summary || t('meetings.review.reanalyze')}
                </p>
            </section>

            <section className={card}>
                <h2 className={`text-sm font-semibold ${heading}`}>{t('meetings.review.leadership')}</h2>
                {review?.leadership_status === 'pending_subject' ? (
                    <p className={`mt-2 text-sm ${muted}`}>{t('meetings.review.notFound')}</p>
                ) : null}
                {(!review || review.leadership_status === 'skipped') ? (
                    <p className={`mt-2 text-sm ${muted}`}>{t('meetings.review.chooseSubject')}</p>
                ) : null}
                {review?.leadership_status === 'completed' ? (
                    <div className="mt-3 space-y-4">
                        <div className="grid gap-2 sm:grid-cols-2">
                            {(review.indicators || []).map((indicator) => (
                                <div key={indicator.key} className={`rounded-lg border p-3 ${dark ? 'border-white/10' : 'border-slate-100'}`}>
                                    <div className="flex items-center justify-between gap-2">
                                        <p className={`text-sm font-medium ${heading}`}>{t(`meetings.review.${indicator.key}`)}</p>
                                        <span className={`rounded-full px-2 py-0.5 text-xs ${statusClass[indicator.status] || statusClass.insufficient_data}`}>
                                            {t(`meetings.review.${indicator.status === 'needs_attention' ? 'needsAttention' : indicator.status === 'good' ? 'good' : 'insufficient'}`)}
                                        </span>
                                    </div>
                                    <p className={`mt-1 text-xs ${muted}`}>{indicator.reason}</p>
                                </div>
                            ))}
                        </div>
                        <FindingList title={t('meetings.review.worked')} items={review.strengths || []} dark={dark} />
                        <FindingList title={t('meetings.review.improve')} items={review.improvements || []} dark={dark} />
                        {(review.recommendations || []).length > 0 ? (
                            <div>
                                <h3 className={`text-sm font-semibold ${heading}`}>{t('meetings.review.next')}</h3>
                                <ol className={`mt-2 list-decimal space-y-1 pl-5 text-sm ${dark ? 'text-slate-200' : 'text-slate-700'}`}>
                                    {review.recommendations.slice(0, 3).map((item) => <li key={item}>{item}</li>)}
                                </ol>
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </section>

            <section className="space-y-3">
                <h2 className={`text-sm font-semibold ${heading}`}>{t('meetings.review.outcome')}</h2>
                <CappedList
                    title={t('meetings.review.decisions')}
                    items={review?.decisions || result.decisions || []}
                    empty="—"
                    dark={dark}
                    render={(item) => (
                        <li key={item.text} className={`text-sm ${dark ? 'text-slate-200' : 'text-slate-700'}`}>
                            {item.text}
                            <Evidence item={item} dark={dark} />
                        </li>
                    )}
                />
                <section className={card}>
                    <h3 className={`text-sm font-semibold ${heading}`}>{t('meetings.review.actions')}</h3>
                    <div className="mt-3 hidden md:block">
                        <table className="min-w-full text-sm">
                            <thead className={muted}>
                                <tr>
                                    <th className="py-2 text-left font-medium">{t('meetings.review.person')}</th>
                                    <th className="py-2 text-left font-medium">{t('meetings.review.action')}</th>
                                    <th className="py-2 text-left font-medium">{t('meetings.review.due')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(review?.actions || result.action_items || []).slice(0, 12).map((item) => (
                                    <tr key={`${item.owner}-${item.task}`} className="border-t border-slate-200/10">
                                        <td className="py-2 pr-3">{item.owner || t('meetings.review.missingOwner')}</td>
                                        <td className="py-2 pr-3">{item.task}</td>
                                        <td className="py-2">{item.deadline_raw || item.deadline_at || t('meetings.review.missingDeadline')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ul className="mt-3 space-y-2 md:hidden">
                        {(review?.actions || result.action_items || []).slice(0, 12).map((item) => (
                            <li key={`${item.owner}-${item.task}-card`} className={`rounded-lg border p-3 text-sm ${dark ? 'border-white/10' : 'border-slate-100'}`}>
                                <p className="font-medium">{item.owner || t('meetings.review.missingOwner')}</p>
                                <p className="mt-1">{item.task}</p>
                                <p className={`mt-1 text-xs ${muted}`}>{item.deadline_raw || item.deadline_at || t('meetings.review.missingDeadline')}</p>
                                <Evidence item={item} dark={dark} />
                            </li>
                        ))}
                    </ul>
                </section>
                <section className={card}>
                    <h3 className={`text-sm font-semibold ${heading}`}>{t('meetings.review.commitments')}</h3>
                    <p className={`mt-2 text-sm ${muted}`}>
                        {t('meetings.review.detected', { count: review?.commitments?.detected ?? (result.commitments_detected || []).length })}
                        {' · '}
                        {t('meetings.review.promoted', { count: promoted })}
                        {' · '}
                        {t('meetings.review.needReview', { count: review?.commitments?.need_review ?? 0 })}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Link href={route('commitments.index')} className={`rounded-lg border px-3 py-2 text-xs ${dark ? 'border-white/10' : 'border-slate-300'}`}>{t('meetings.review.openCommitments')}</Link>
                    </div>
                </section>
                <CappedList
                    title={t('meetings.review.risks')}
                    items={(review?.risks || result.risks || []).slice(0, review ? undefined : 5).filter(Boolean)}
                    empty={t('meetings.review.noRisks')}
                    dark={dark}
                    render={(item) => (
                        <li key={item.text} className={`text-sm ${dark ? 'text-slate-200' : 'text-slate-700'}`}>
                            {item.severity ? <span className="mr-2 text-xs uppercase">{item.severity}</span> : null}
                            {item.text}
                            <Evidence item={item} dark={dark} />
                        </li>
                    )}
                />
                <CappedList
                    title={t('meetings.review.questions')}
                    items={review?.open_questions || result.open_questions || []}
                    empty={t('meetings.review.noQuestions')}
                    dark={dark}
                    render={(item) => <li key={item.text} className={`text-sm ${dark ? 'text-slate-200' : 'text-slate-700'}`}>{item.text}</li>}
                />
                <CappedList
                    title={t('meetings.review.followUps')}
                    items={review?.follow_ups || result.follow_ups || []}
                    empty="—"
                    dark={dark}
                    render={(item) => (
                        <li key={item.text} className={`text-sm ${dark ? 'text-slate-200' : 'text-slate-700'}`}>
                            {item.person ? `${item.person}: ` : ''}{item.text}
                            {item.project ? <span className={`ml-2 text-xs ${muted}`}>{item.project}</span> : null}
                        </li>
                    )}
                />
            </section>

            <section className={card}>
                <h2 className={`text-sm font-semibold ${heading}`}>{t('meetings.review.assistant')}</h2>
                <ul className={`mt-2 space-y-1 text-sm ${dark ? 'text-slate-200' : 'text-slate-700'}`}>
                    <li>{t('meetings.review.created', { count: review?.assistant?.commitments_created ?? promoted })}</li>
                    <li>{t('meetings.review.needConfirmation', { count: review?.assistant?.commitments_need_confirmation ?? 0 })}</li>
                    <li>{t('meetings.review.noDeadline', { count: review?.assistant?.actions_without_deadline ?? metrics.actions_without_deadline ?? 0 })}</li>
                    <li>{t('meetings.review.unresolvedPeople', { count: review?.assistant?.unresolved_participants ?? 0 })}</li>
                </ul>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Link href={route('commitments.index')} className={`rounded-lg border px-3 py-2 text-xs ${dark ? 'border-white/10' : 'border-slate-300'}`}>{t('meetings.review.reviewCommitments')}</Link>
                    <a href="#meeting-actions" className={`rounded-lg border px-3 py-2 text-xs ${dark ? 'border-white/10' : 'border-slate-300'}`}>{t('meetings.review.addDeadlines')}</a>
                    <a href="#participants" className={`rounded-lg border px-3 py-2 text-xs ${dark ? 'border-white/10' : 'border-slate-300'}`}>{t('meetings.review.resolveParticipants')}</a>
                </div>
            </section>
        </div>
    );
}

function FindingList({ title, items, dark }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div>
            <h3 className={`text-sm font-semibold ${dark ? 'text-white' : 'text-slate-900'}`}>{title}</h3>
            <ul className="mt-2 space-y-3">
                {items.slice(0, 5).map((item) => (
                    <li key={item.key || item.title}>
                        <p className={`text-sm font-medium ${dark ? 'text-slate-100' : 'text-slate-800'}`}>{item.title}</p>
                        <p className={`text-sm ${dark ? 'text-slate-300' : 'text-slate-600'}`}>{item.observation}</p>
                        {item.suggestion ? <p className={`mt-1 text-xs ${dark ? 'text-slate-400' : 'text-slate-500'}`}>{item.suggestion}</p> : null}
                        {(item.evidence || []).slice(0, 1).map((evidence) => (
                            <Evidence key={evidence.excerpt} item={{ evidence }} dark={dark} />
                        ))}
                    </li>
                ))}
            </ul>
        </div>
    );
}
