import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';

export default function AutomationRunsIndex() {
    const { runs = [], filters = {}, types = [], statuses = [] } = usePage().props;

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('automation-runs.index'),
            {
                type: event.target.type.value,
                status: event.target.status.value,
                date: event.target.date.value,
                automation_id: event.target.automation_id.value,
                failed: event.target.failed.checked ? 1 : '',
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title="Automation Runs">
            <Head title="Automation Runs" />
            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <p className="text-sm text-slate-600">Operational automation runs. No email bodies or transcripts.</p>
                    <form className="mt-4 grid gap-2 md:grid-cols-6" onSubmit={applyFilters}>
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
                        <input name="automation_id" defaultValue={filters.automation_id || ''} placeholder="Automation id" className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="failed" defaultChecked={!!filters.failed} />
                            Failed only
                        </label>
                        <button type="submit" className="rounded-lg border border-slate-300 px-3 text-sm">Filter</button>
                    </form>
                </section>

                <div className="overflow-x-auto rounded-xl border bg-white">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Run key</th>
                                <th className="px-3 py-2">Started</th>
                                <th className="px-3 py-2">Duration</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Outcome</th>
                                <th className="px-3 py-2">Delivery</th>
                                <th className="px-3 py-2">Error</th>
                                <th className="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.map((run) => (
                                <tr key={run.id} className="border-t">
                                    <td className="px-3 py-2 font-mono text-xs">{run.run_key}</td>
                                    <td className="px-3 py-2">{run.started_at || '—'}</td>
                                    <td className="px-3 py-2">{run.duration_ms != null ? `${run.duration_ms} ms` : '—'}</td>
                                    <td className="px-3 py-2">{run.status}</td>
                                    <td className="px-3 py-2">{run.outcome_code || '—'}</td>
                                    <td className="px-3 py-2">{run.delivery_status || '—'}</td>
                                    <td className="px-3 py-2 text-rose-700">{run.safe_error || '—'}</td>
                                    <td className="px-3 py-2">
                                        {run.retryable ? (
                                            <button
                                                type="button"
                                                className="rounded-lg border px-2 py-1 text-xs"
                                                onClick={() => router.post(route('automation-runs.retry', run.id))}
                                            >
                                                Retry
                                            </button>
                                        ) : null}
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
