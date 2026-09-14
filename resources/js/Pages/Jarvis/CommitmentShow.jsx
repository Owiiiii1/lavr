import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm } from '@inertiajs/react';

function Block({ title, children }) {
    return (
        <section className="mt-6">
            <h2 className="text-sm font-semibold text-white">{title}</h2>
            <div className="mt-2 text-sm text-slate-300">{children}</div>
        </section>
    );
}

export default function CommitmentShow({ commitment, people = [], projects = [], proposals = [] }) {
    const { t } = useTranslation();
    const form = useForm({
        title: commitment.title || '',
        expected_result: commitment.expected_result || '',
        person_id: commitment.person?.id || '',
        project_id: commitment.project?.id || '',
        deadline_at: commitment.deadline_at ? commitment.deadline_at.slice(0, 16) : '',
        deadline_raw: commitment.deadline_raw || '',
        notes: '',
    });
    const evidenceForm = useForm({ evidence_type: 'completion', excerpt: '' });

    return (
        <LavrAppShell>
            <Head title={commitment.title} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/commitments" className="text-sm text-sky-300">{t('commitments.back')}</Link>
                <p className="mt-3 text-[11px] uppercase tracking-[0.18em] text-slate-500">{t(`commitments.status_${commitment.status}`)}</p>
                {commitment.unresolved_person ? <p className="mt-2 text-sm text-amber-200">{t('commitments.unresolved')}</p> : null}
                <h1 className="mt-2 text-2xl font-semibold text-white">{commitment.title}</h1>

                <Block title={t('commitments.who')}>{commitment.person?.display_name || commitment.person_name_raw || '—'}</Block>
                <Block title={t('commitments.what')}>{commitment.title}</Block>
                <Block title={t('commitments.expected')}>{commitment.expected_result || '—'}</Block>
                <Block title={t('commitments.deadline')}>{commitment.deadline_at ? commitment.deadline_at.slice(0, 16).replace('T', ' ') : (commitment.deadline_raw || '—')}</Block>
                <Block title={t('commitments.project')}>{commitment.project?.name || '—'}</Block>
                <Block title={t('commitments.statusLabel')}>{t(`commitments.status_${commitment.status}`)}</Block>

                <form className="mt-6 space-y-2" onSubmit={(event) => { event.preventDefault(); form.patch(route('jarvis.commitments.update', commitment.id)); }}>
                    <input className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                    <input className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" placeholder={t('commitments.expected')} value={form.data.expected_result} onChange={(e) => form.setData('expected_result', e.target.value)} />
                    <select className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" value={form.data.person_id} onChange={(e) => form.setData('person_id', e.target.value)}>
                        <option value="">{t('commitments.person')}</option>
                        {people.map((person) => <option key={person.id} value={person.id}>{person.display_name}</option>)}
                    </select>
                    <select className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" value={form.data.project_id} onChange={(e) => form.setData('project_id', e.target.value)}>
                        <option value="">{t('commitments.project')}</option>
                        {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                    </select>
                    <input type="datetime-local" className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" value={form.data.deadline_at} onChange={(e) => form.setData('deadline_at', e.target.value)} />
                    <button type="submit" className="min-h-11 rounded-2xl bg-sky-500 px-4 text-sm font-semibold">{t('common.save')}</button>
                </form>

                <div className="mt-4 flex flex-wrap gap-2">
                    {commitment.lifecycle_status === 'detected' ? (
                        <>
                            <button type="button" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => router.post(route('jarvis.commitments.confirm', commitment.id), form.data)}>{t('commitments.confirm')}</button>
                            <button type="button" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => router.post(route('jarvis.commitments.dismiss', commitment.id))}>{t('commitments.dismiss')}</button>
                        </>
                    ) : null}
                    {['open', 'due_soon', 'overdue', 'likely_done'].includes(commitment.status) ? (
                        <>
                            <button type="button" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => router.post(route('jarvis.commitments.likely-done', commitment.id), { note: evidenceForm.data.excerpt })}>{t('commitments.likelyDone')}</button>
                            <button type="button" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => router.post(route('jarvis.commitments.complete', commitment.id), { note: form.data.notes })}>{t('commitments.markConfirmed')}</button>
                            <button type="button" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => router.post(route('jarvis.commitments.cancel', commitment.id))}>{t('commitments.cancel')}</button>
                        </>
                    ) : null}
                </div>

                <Block title={t('commitments.evidence')}>
                    <ul className="space-y-2">
                        {(commitment.evidence || []).map((row) => (
                            <li key={row.id}>{row.evidence_type}: {row.excerpt || '—'}</li>
                        ))}
                    </ul>
                    <form className="mt-3 space-y-2" onSubmit={(event) => { event.preventDefault(); evidenceForm.post(route('jarvis.commitments.evidence', commitment.id)); }}>
                        <select className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" value={evidenceForm.data.evidence_type} onChange={(e) => evidenceForm.setData('evidence_type', e.target.value)}>
                            {['promise', 'deadline', 'progress', 'delivery', 'completion', 'confirmation', 'other'].map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                        <input className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" placeholder={t('commitments.excerpt')} value={evidenceForm.data.excerpt} onChange={(e) => evidenceForm.setData('excerpt', e.target.value)} />
                        <button type="submit" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm">{t('commitments.addEvidence')}</button>
                    </form>
                </Block>

                <Block title={t('commitments.source')}>
                    {commitment.meeting ? (
                        <Link href={`/lavr/meetings/${commitment.meeting.id}`} className="text-sky-300">{commitment.meeting.title}</Link>
                    ) : commitment.source_type}
                </Block>

                <Block title={t('proactive.title')}>
                    {(proposals || []).length === 0 ? (
                        <p>{t('proactive.empty')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {proposals.map((item) => (
                                <li key={item.id}>
                                    <Link href={`/lavr/proactive/${item.id}`} className="text-sky-300">{item.title}</Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Block>

                <Block title={t('commitments.history')}>
                    <ul className="space-y-1">
                        {(commitment.history || []).map((row) => (
                            <li key={row.id}>{row.from_status || '—'} → {row.to_status}</li>
                        ))}
                    </ul>
                </Block>
            </div>
        </LavrAppShell>
    );
}
