import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';
import ProactiveProposalCard from '@/personal-workspace/ProactiveProposalCard';

function Section({ title, items, empty }) {
    return (
        <section className="mt-6">
            <h2 className="text-xs uppercase tracking-[0.16em] text-slate-500">{title}</h2>
            {items.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">{empty}</p>
            ) : (
                <div className="mt-3 space-y-3">
                    {items.map((item) => (
                        <ProactiveProposalCard key={item.id} proposal={item} />
                    ))}
                </div>
            )}
        </section>
    );
}

export default function ProactiveIndex({
    needs_attention = [],
    suggested = [],
    waiting_approval = [],
    recently_handled = [],
    dismissed = [],
}) {
    const { t } = useTranslation();

    return (
        <LavrAppShell>
            <Head title={t('proactive.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">{t('common.lavr')}</p>
                <h1 className="mt-1 text-2xl font-semibold text-white">{t('proactive.title')}</h1>
                <p className="mt-2 max-w-lg text-sm leading-6 text-slate-400">{t('proactive.intro')}</p>
                <Section title={t('proactive.needsAttention')} items={needs_attention} empty={t('proactive.empty')} />
                <Section title={t('proactive.suggested')} items={suggested} empty={t('proactive.empty')} />
                <Section title={t('proactive.waitingApproval')} items={waiting_approval} empty={t('proactive.empty')} />
                <Section title={t('proactive.recentlyHandled')} items={recently_handled} empty={t('proactive.empty')} />
                <Section title={t('proactive.dismissed')} items={dismissed} empty={t('proactive.empty')} />
                <Link href="/lavr/more" className="mt-8 inline-flex text-sm text-sky-300">{t('proactive.back')}</Link>
            </div>
        </LavrAppShell>
    );
}
