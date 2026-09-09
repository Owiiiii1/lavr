import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

function Block({ title, children }) {
    return (
        <section className="mt-6">
            <h2 className="text-sm font-semibold text-white">{title}</h2>
            <div className="mt-2 text-sm text-slate-300">{children}</div>
        </section>
    );
}

export default function MeetingShow() {
    const { t } = useTranslation();
    const { meeting, people = [], pollSeconds = 3 } = usePage().props;
    const result = meeting.analysis?.result || {};
    const [transcriptOpen, setTranscriptOpen] = useState(false);
    const [transcriptQuery, setTranscriptQuery] = useState('');

    useEffect(() => {
        if (!['pending', 'processing'].includes(meeting.analysis_status)) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            router.reload({ only: ['meeting'] });
        }, Math.max(2, pollSeconds) * 1000);

        return () => window.clearInterval(timer);
    }, [meeting.analysis_status, meeting.id, pollSeconds]);

    const transcript = meeting.artifact?.normalized_text || meeting.artifact?.original_text || '';
    const filteredTranscript = useMemo(() => {
        if (!transcriptQuery.trim()) {
            return transcript;
        }

        return transcript
            .split('\n')
            .filter((line) => line.toLowerCase().includes(transcriptQuery.toLowerCase()))
            .join('\n');
    }, [transcript, transcriptQuery]);

    return (
        <LavrAppShell>
            <Head title={meeting.title} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/meetings" className="text-sm text-sky-300">{t('meetings.back')}</Link>
                <p className="mt-3 text-[11px] uppercase tracking-[0.18em] text-slate-500">{t(`meetings.status_${meeting.analysis_status}`)}</p>
                <h1 className="mt-2 text-2xl font-semibold text-white">{meeting.title}</h1>
                <p className="mt-2 text-sm text-slate-400">
                    {meeting.started_at ? meeting.started_at.slice(0, 16).replace('T', ' ') : t('meetings.noDate')}
                    {meeting.project?.name ? ` · ${meeting.project.name}` : ''}
                </p>
                {meeting.analysis?.error_message ? <p className="mt-3 text-sm text-red-400">{meeting.analysis.error_message}</p> : null}
                <button type="button" className="mt-4 min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => router.post(route('jarvis.meetings.rerun', meeting.id))}>
                    {t('meetings.rerun')}
                </button>

                <Block title={t('meetings.overview')}>
                    <p>{result.summary?.executive || meeting.summary || '—'}</p>
                </Block>
                <Block title={t('meetings.summary')}>
                    <ul className="list-disc pl-5">
                        {(result.summary?.outcomes || []).map((item) => <li key={item}>{item}</li>)}
                    </ul>
                    {(result.summary?.attention || []).length > 0 ? (
                        <p className="mt-2 text-amber-200">{(result.summary.attention || []).join(' · ')}</p>
                    ) : null}
                </Block>
                <Block title={t('meetings.participants')}>
                    <ul className="space-y-3">
                        {(meeting.participants || []).map((participant) => (
                            <li key={participant.id} className="rounded-2xl border border-white/10 p-3">
                                <p>{participant.display_name} {participant.resolved ? `→ ${participant.person_name}` : t('meetings.unresolved')}</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <form
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            router.post(route('jarvis.meetings.participants.link', [meeting.id, participant.id]), {
                                                person_id: event.target.person_id.value,
                                            });
                                        }}
                                        className="flex gap-2"
                                    >
                                        <select name="person_id" defaultValue={participant.person_id || ''} className="min-h-11 rounded-xl border border-white/10 bg-white/5 px-2 text-sm">
                                            <option value="">{t('meetings.linkPerson')}</option>
                                            {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                                        </select>
                                        <button type="submit" className="rounded-xl border border-white/10 px-3 text-xs">{t('meetings.link')}</button>
                                    </form>
                                    {participant.resolved ? (
                                        <button type="button" className="rounded-xl border border-white/10 px-3 text-xs" onClick={() => router.post(route('jarvis.meetings.participants.unlink', [meeting.id, participant.id]))}>{t('meetings.unlink')}</button>
                                    ) : (
                                        <button type="button" className="rounded-xl border border-white/10 px-3 text-xs" onClick={() => router.post(route('jarvis.meetings.participants.create-person', [meeting.id, participant.id]))}>{t('meetings.createPerson')}</button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Block>
                <Block title={t('meetings.decisions')}>
                    <List items={result.decisions} field="text" />
                </Block>
                <Block title={t('meetings.actions')}>
                    <ul className="space-y-2">
                        {(result.action_items || []).map((item) => (
                            <li key={item.task}>{(item.owner ? `${item.owner}: ` : '') + item.task}</li>
                        ))}
                    </ul>
                </Block>
                <Block title={t('meetings.commitments')}>
                    <ul className="space-y-2">
                        {(result.commitments_detected || []).map((item) => (
                            <li key={item.action}>{(item.person_name || item.person_ref || '—') + ': ' + item.action}</li>
                        ))}
                    </ul>
                    <p className="mt-2 text-xs text-slate-500">{t('meetings.commitmentsHint')}</p>
                </Block>
                <Block title={t('meetings.deadlines')}>
                    <ul className="space-y-2">
                        {(result.deadlines || []).map((item) => (
                            <li key={item.text}>{item.text} {item.deadline_at || item.deadline_raw || ''}</li>
                        ))}
                    </ul>
                </Block>
                <Block title={t('meetings.questions')}>
                    <List items={result.open_questions} field="text" />
                </Block>
                <Block title={t('meetings.risks')}>
                    <List items={result.risks} field="text" />
                </Block>
                <Block title={t('meetings.followUps')}>
                    <List items={result.follow_ups} field="text" />
                </Block>
                <Block title={t('meetings.transcript')}>
                    <button type="button" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => setTranscriptOpen((value) => !value)}>
                        {transcriptOpen ? t('meetings.hideTranscript') : t('meetings.showTranscript')}
                    </button>
                    {transcriptOpen ? (
                        <div className="mt-3">
                            <input value={transcriptQuery} onChange={(event) => setTranscriptQuery(event.target.value)} placeholder={t('meetings.searchTranscript')} className="mb-2 min-h-11 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm" />
                            <pre className="max-h-80 overflow-auto whitespace-pre-wrap rounded-2xl bg-white/5 p-3 text-xs text-slate-300">{filteredTranscript || '—'}</pre>
                        </div>
                    ) : null}
                </Block>
            </div>
        </LavrAppShell>
    );
}

function List({ items = [], field }) {
    if (items.length === 0) {
        return <p>—</p>;
    }

    return (
        <ul className="list-disc pl-5">
            {items.map((item) => <li key={item[field] || JSON.stringify(item)}>{item[field]}</li>)}
        </ul>
    );
}
