import { Link, router } from '@inertiajs/react';
import { useTranslation } from '@/locales/useTranslation';

export default function ProactiveProposalCard({ proposal, compact = false }) {
    const { t } = useTranslation();
    if (!proposal) {
        return null;
    }

    const dismiss = (reason) => router.post(route('jarvis.proactive.dismiss', proposal.id), { reason }, { preserveScroll: true });

    return (
        <article className="rounded-2xl border border-white/10 bg-white/5 p-4">
            <p className="text-[11px] uppercase tracking-[0.14em] text-slate-500">
                {t(`proactive.severity_${proposal.severity}`)} · {t(`proactive.type_${proposal.proposal_type}`)}
            </p>
            <h3 className="mt-1 text-sm font-semibold text-white">{proposal.title}</h3>
            <p className="mt-2 text-sm leading-6 text-slate-300">{proposal.rationale}</p>
            {proposal.draft_status === 'draft' ? (
                <p className="mt-2 text-xs uppercase tracking-[0.14em] text-amber-200">{t('proactive.draftNotSent')}</p>
            ) : null}
            <div className="mt-3 flex flex-wrap gap-2 text-xs text-sky-300">
                {proposal.person ? (
                    <Link href={`/lavr/people/${proposal.person.id}`}>{proposal.person.display_name}</Link>
                ) : null}
                {proposal.project ? (
                    <Link href={`/lavr/projects/${proposal.project.id}`}>{proposal.project.name}</Link>
                ) : null}
                {proposal.commitment ? (
                    <Link href={`/lavr/commitments/${proposal.commitment.id}`}>{proposal.commitment.title}</Link>
                ) : null}
                {proposal.source_href ? <Link href={proposal.source_href}>{t('proactive.openSource')}</Link> : null}
            </div>
            {proposal.status === 'pending' && !compact ? (
                <div className="mt-4 flex flex-wrap gap-2">
                    <button type="button" className="min-h-10 rounded-xl bg-sky-500 px-3 text-xs font-semibold text-white" onClick={() => router.post(route('jarvis.proactive.approve', proposal.id), {}, { preserveScroll: true })}>
                        {t('proactive.approve')}
                    </button>
                    <Link href={`/lavr/proactive/${proposal.id}`} className="inline-flex min-h-10 items-center rounded-xl border border-white/10 px-3 text-xs">
                        {t('proactive.edit')}
                    </Link>
                    <button type="button" className="min-h-10 rounded-xl border border-white/10 px-3 text-xs" onClick={() => router.post(route('jarvis.proactive.snooze', proposal.id), { when: 'later_today' }, { preserveScroll: true })}>
                        {t('proactive.snoozeLater')}
                    </button>
                    <button type="button" className="min-h-10 rounded-xl border border-white/10 px-3 text-xs" onClick={() => router.post(route('jarvis.proactive.snooze', proposal.id), { when: 'tomorrow' }, { preserveScroll: true })}>
                        {t('proactive.snoozeTomorrow')}
                    </button>
                    <button type="button" className="min-h-10 rounded-xl border border-white/10 px-3 text-xs" onClick={() => dismiss('not_relevant')}>
                        {t('proactive.dismiss')}
                    </button>
                </div>
            ) : null}
            {compact && proposal.status === 'pending' ? (
                <Link href={`/lavr/proactive/${proposal.id}`} className="mt-3 inline-flex min-h-10 items-center text-xs text-sky-300">
                    {t('proactive.open')}
                </Link>
            ) : null}
        </article>
    );
}
