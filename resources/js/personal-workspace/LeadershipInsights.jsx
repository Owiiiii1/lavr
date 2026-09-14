import { Link } from '@inertiajs/react';

export default function LeadershipInsights({ title, metrics = {}, findings = [], emptyLabel = '—' }) {
    return (
        <section className="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm leading-6 text-slate-200">
            <h2 className="text-xs uppercase tracking-[0.14em] text-slate-500">{title}</h2>
            {Object.keys(metrics).length > 0 ? (
                <dl className="mt-3 grid grid-cols-2 gap-2">
                    {Object.entries(metrics).map(([key, value]) => (
                        <div key={key}>
                            <dt className="text-[11px] uppercase tracking-[0.12em] text-slate-500">{key}</dt>
                            <dd>{value ?? '—'}</dd>
                        </div>
                    ))}
                </dl>
            ) : null}
            {findings.length === 0 ? (
                <p className="mt-3 text-slate-500">{emptyLabel}</p>
            ) : (
                <ul className="mt-3 space-y-2">
                    {findings.map((item, index) => (
                        <li key={item.id || index}>
                            <p>{item.observation || item.title}</p>
                            {(item.evidence_refs || []).slice(0, 4).map((ref) => (
                                <Link key={`${ref.type}-${ref.id}`} href={ref.href || '#'} className="mr-2 text-xs text-sky-300">
                                    {ref.label || ref.type}
                                </Link>
                            ))}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
