import AdminLayout from '@/Layouts/AdminLayout';
import { Head } from '@inertiajs/react';

export default function ProductionReadiness({ readiness, secrets = [] }) {
    const sections = readiness?.sections || [];
    const live = readiness?.live_validation || [];

    return (
        <AdminLayout title="Production readiness">
            <Head title="Production readiness" />
            <div className="space-y-6">
                <p className="text-sm text-slate-600">CODEBASE READY FOR LIVE VALIDATION — not client acceptance. IMPLEMENTED is not LIVE VALIDATED.</p>
                <p className="text-sm font-medium text-slate-800">Overall: {(readiness?.overall || 'warn').toUpperCase()}</p>
                {sections.map((section) => (
                    <section key={section.title} className="rounded-xl border border-slate-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-slate-900">{section.title}</h2>
                        <ul className="mt-3 space-y-1 text-sm text-slate-600">
                            {(section.items || []).map((item) => (
                                <li key={`${section.title}-${item.key}`}>
                                    {(item.acceptance || 'warn').toUpperCase()} · {item.key} · {item.message}
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                {live.length > 0 && !sections.some((section) => section.title === 'Validation') ? (
                    <section className="rounded-xl border border-slate-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-slate-900">Validation</h2>
                        <ul className="mt-3 space-y-1 text-sm text-slate-600">
                            {live.map((item) => (
                                <li key={item.label}>{(item.state || 'not_validated').toUpperCase()} · {item.label}</li>
                            ))}
                        </ul>
                    </section>
                ) : null}
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h2 className="text-sm font-semibold text-slate-900">Secret audit</h2>
                    <p className="mt-1 text-xs text-slate-500">Paths and categories only. Values are never shown.</p>
                    <ul className="mt-3 space-y-1 text-sm text-slate-600">
                        {secrets.map((item) => (
                            <li key={`${item.path}-${item.category}`}>{item.status} · {item.category} · {item.path}</li>
                        ))}
                    </ul>
                </section>
            </div>
        </AdminLayout>
    );
}
