import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function ProactiveShow({ proposal }) {
    const { t } = useTranslation();
    const form = useForm({
        draft_body: proposal.draft_body || '',
    });

    return (
        <LavrAppShell>
            <Head title={proposal.title} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/proactive" className="text-sm text-sky-300">{t('proactive.back')}</Link>
                <p className="mt-3 text-[11px] uppercase tracking-[0.14em] text-slate-500">
                    {t(`proactive.severity_${proposal.severity}`)} · {t(`proactive.status_${proposal.status}`)}
                </p>
                <h1 className="mt-2 text-2xl font-semibold text-white">{proposal.title}</h1>
                <p className="mt-3 text-sm leading-6 text-slate-300">{proposal.rationale}</p>
                <p className="mt-2 text-xs uppercase tracking-[0.14em] text-amber-200">
                    {proposal.draft_status === 'draft' ? t('proactive.draftNotSent') : t('proactive.notSent')}
                </p>
                {proposal.person ? <p className="mt-3 text-sm">{t('proactive.person')}: {proposal.person.display_name}</p> : null}
                {proposal.project ? <p className="mt-1 text-sm">{t('proactive.project')}: {proposal.project.name}</p> : null}
                {proposal.commitment ? (
                    <p className="mt-1 text-sm">
                        <Link href={`/lavr/commitments/${proposal.commitment.id}`} className="text-sky-300">{proposal.commitment.title}</Link>
                    </p>
                ) : null}

                <form
                    className="mt-6 space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(route('jarvis.proactive.update', proposal.id));
                    }}
                >
                    <label className="block text-xs uppercase tracking-[0.14em] text-slate-500">{t('proactive.editDraft')}</label>
                    <textarea
                        className="min-h-32 w-full rounded-2xl bg-black/30 px-3 py-3 text-sm"
                        value={form.data.draft_body}
                        onChange={(event) => form.setData('draft_body', event.target.value)}
                    />
                    <button type="submit" className="min-h-11 rounded-2xl border border-white/10 px-4 text-sm">{t('common.save')}</button>
                </form>

                {proposal.status === 'pending' ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        <button type="button" className="min-h-11 rounded-2xl bg-sky-500 px-4 text-sm font-semibold" onClick={() => router.post(route('jarvis.proactive.approve', proposal.id))}>
                            {t('proactive.approve')}
                        </button>
                        <button type="button" className="min-h-11 rounded-xl border border-white/10 px-3 text-sm" onClick={() => router.post(route('jarvis.proactive.snooze', proposal.id), { when: 'later_today' })}>
                            {t('proactive.snoozeLater')}
                        </button>
                        <button type="button" className="min-h-11 rounded-xl border border-white/10 px-3 text-sm" onClick={() => router.post(route('jarvis.proactive.snooze', proposal.id), { when: 'tomorrow' })}>
                            {t('proactive.snoozeTomorrow')}
                        </button>
                        <button type="button" className="min-h-11 rounded-xl border border-white/10 px-3 text-sm" onClick={() => router.post(route('jarvis.proactive.dismiss', proposal.id), { reason: 'not_relevant' })}>
                            {t('proactive.dismiss')}
                        </button>
                        {proposal.source_href ? (
                            <Link href={proposal.source_href} className="inline-flex min-h-11 items-center text-sm text-sky-300">{t('proactive.openSource')}</Link>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </LavrAppShell>
    );
}
