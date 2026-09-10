import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function Section({ title, children }) {
    return (
        <section className="mt-5 rounded-2xl border border-white/10 bg-white/5 p-4">
            <h2 className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{title}</h2>
            <div className="mt-3">{children}</div>
        </section>
    );
}

function Item({ item }) {
    return (
        <li>
            <Link href={`/lavr/commitments/${item.id}`} className="block rounded-xl bg-black/20 px-3 py-3">
                <p className="text-sm text-white">{item.title}</p>
                <p className="mt-1 text-xs text-slate-400">
                    {item.person?.display_name || item.person_name_raw || '—'}
                    {item.deadline_at ? ` · ${item.deadline_at.slice(0, 16).replace('T', ' ')}` : ''}
                    {item.unresolved_person ? ' · unresolved' : ''}
                </p>
            </Link>
        </li>
    );
}

export default function Commitments({ sections = {}, people = [], projects = [] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const form = useForm({ title: '', expected_result: '', person_id: '', project_id: '', deadline_at: '', notes: '' });
    const keys = ['overdue', 'due_soon', 'open', 'detected', 'likely_done', 'confirmed'];

    return (
        <LavrAppShell>
            <Head title={t('commitments.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">{t('people.phase', { phase: '6' })}</p>
                <div className="mt-2 flex items-start justify-between gap-3">
                    <h1 className="text-2xl font-semibold text-white">{t('commitments.title')}</h1>
                    <button type="button" className="min-h-11 rounded-2xl bg-sky-500 px-4 text-sm font-semibold text-white" onClick={() => setOpen(true)}>
                        {t('commitments.create')}
                    </button>
                </div>
                <p className="mt-2 max-w-lg text-sm text-slate-400">{t('commitments.hint')}</p>

                {open ? (
                    <form
                        className="mt-4 space-y-2 rounded-2xl border border-white/10 p-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(route('jarvis.commitments.store'), { onSuccess: () => setOpen(false) });
                        }}
                    >
                        <input className="w-full rounded-xl bg-black/30 px-3 py-3 text-sm" placeholder={t('commitments.action')} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
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
                        <div className="flex gap-2">
                            <button type="submit" className="min-h-11 rounded-2xl bg-sky-500 px-4 text-sm font-semibold">{t('common.save')}</button>
                            <button type="button" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm" onClick={() => setOpen(false)}>{t('common.close')}</button>
                        </div>
                    </form>
                ) : null}

                {keys.map((key) => (
                    <Section key={key} title={t(`commitments.section_${key}`)}>
                        {(sections[key] || []).length === 0 ? (
                            <p className="text-sm text-slate-500">{t('commitments.emptySection')}</p>
                        ) : (
                            <ul className="space-y-2">
                                {sections[key].map((item) => <Item key={item.id} item={item} />)}
                            </ul>
                        )}
                    </Section>
                ))}
            </div>
        </LavrAppShell>
    );
}
