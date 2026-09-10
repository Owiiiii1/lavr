import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link } from '@inertiajs/react';

function Section({ title, rows }) {
    if (!rows || rows.length === 0) {
        return null;
    }

    return (
        <section className="rounded-2xl border border-white/10 bg-white/5 p-4">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{title}</h2>
            <ul className="space-y-2">
                {rows.map((item, index) => (
                    <li key={item.dedupe_key || `${item.type}-${index}`} className="rounded-xl bg-black/20 px-3 py-2">
                        {item.deep_link ? (
                            <Link href={item.deep_link} className="block">
                                <p className="text-sm text-white">{item.title}</p>
                                {item.summary ? <p className="text-xs text-slate-400">{item.summary}</p> : null}
                            </Link>
                        ) : (
                            <>
                                <p className="text-sm text-white">{item.title}</p>
                                {item.summary ? <p className="text-xs text-slate-400">{item.summary}</p> : null}
                            </>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default function BriefShow({ brief }) {
    const { t } = useTranslation();
    const sections = brief?.sections || {};
    const errors = brief?.source_snapshot?.errors || [];

    const copy = () => {
        if (brief?.copy_text && navigator.clipboard) {
            navigator.clipboard.writeText(brief.copy_text);
        }
    };

    return (
        <LavrAppShell>
            <Head title={t('brief.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <Link href="/lavr/briefs" className="text-sm text-sky-300">{t('brief.back')}</Link>
                <h1 className="mt-3 text-2xl font-semibold text-white">{t('brief.title')}</h1>
                <p className="mt-2 max-w-xl text-sm leading-6 text-slate-300">{brief?.summary}</p>
                <p className="mt-1 text-xs text-slate-500">
                    {brief?.generated_for} · {brief?.status}
                </p>
                <button type="button" onClick={copy} className="mt-3 text-sm text-sky-300">
                    {t('brief.copy')}
                </button>

                <div className="mt-6 space-y-4">
                    <Section title={t('brief.attention')} rows={sections.attention} />
                    <Section title={t('brief.today')} rows={sections.today} />
                    <Section title={t('brief.commitments')} rows={sections.commitments} />
                    <Section title={t('brief.meetings')} rows={sections.meetings} />
                    <Section title={t('brief.people')} rows={sections.people} />
                    <Section title={t('brief.projects')} rows={sections.projects} />
                    <Section title={t('brief.inbox')} rows={sections.inbox} />
                    <Section title={t('brief.risks')} rows={sections.risks} />
                    <Section title={t('brief.followups')} rows={sections.followups} />
                </div>

                {errors.length > 0 ? (
                    <p className="mt-6 text-sm text-amber-300">{errors.filter(Boolean).join(' ')}</p>
                ) : null}
            </div>
        </LavrAppShell>
    );
}
