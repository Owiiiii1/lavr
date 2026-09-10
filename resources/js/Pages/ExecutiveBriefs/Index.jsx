import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';

export default function ExecutiveBriefsIndex() {
    const { briefs = [], filters = {}, types = [], statuses = [] } = usePage().props;

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('executive-briefs.index'),
            {
                type: event.target.type.value,
                status: event.target.status.value,
                date: event.target.date.value,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title="Executive Briefs">
            <Head title="Executive Briefs" />
            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <p className="text-sm text-slate-600">Morning executive briefs. No email bodies or transcripts.</p>
                    <form className="mt-4 grid gap-2 md:grid-cols-4" onSubmit={applyFilters}>
                        <select name="type" defaultValue={filters.type || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All types</option>
                            {types.map((type) => (
                                <option key={type} value={type}>{type}</option>
                            ))}
                        </select>
                        <select name="status" defaultValue={filters.status || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <option value="">All statuses</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>{status}</option>
                            ))}
                        </select>
                        <input type="date" name="date" defaultValue={filters.date || ''} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <button type="submit" className="rounded-lg border border-slate-300 px-3 text-sm">Filter</button>
                    </form>
                </section>

                <div className="overflow-x-auto rounded-xl border bg-white">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Date</th>
                                <th className="px-3 py-2">Type</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Run</th>
                                <th className="px-3 py-2">Sources</th>
                                <th className="px-3 py-2">Generated</th>
                                <th className="px-3 py-2">Delivered</th>
                                <th className="px-3 py-2">Error</th>
                                <th className="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {briefs.map((brief) => (
                                <tr key={brief.id} className="border-t">
                                    <td className="px-3 py-2">{brief.generated_for || '—'}</td>
                                    <td className="px-3 py-2">{brief.brief_type}</td>
                                    <td className="px-3 py-2">{brief.status}</td>
                                    <td className="px-3 py-2 font-mono text-xs">{brief.automation_run_id || '—'}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {brief.snapshot?.sources_succeeded}/{brief.snapshot?.sources_attempted}
                                    </td>
                                    <td className="px-3 py-2">{brief.generated_at || '—'}</td>
                                    <td className="px-3 py-2">{brief.delivered_at || brief.delivery_status || '—'}</td>
                                    <td className="px-3 py-2 text-rose-700">{brief.safe_error || '—'}</td>
                                    <td className="px-3 py-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border px-2 py-1 text-xs"
                                            onClick={() => router.post(route('executive-briefs.regenerate', brief.id))}
                                        >
                                            Regenerate
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    );
}
