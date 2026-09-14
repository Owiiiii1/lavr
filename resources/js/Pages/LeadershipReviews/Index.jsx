import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, usePage } from '@inertiajs/react';

export default function LeadershipReviewsIndex() {
    const { reviews = [], filters = {}, types = [], statuses = [] } = usePage().props;

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(
            route('leadership-reviews.index'),
            {
                type: event.target.type.value,
                status: event.target.status.value,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout title="Leadership Reviews">
            <Head title="Leadership Reviews" />
            <div className="space-y-4">
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <p className="text-sm text-slate-600">Process quality reviews. No transcripts, emails, or personality scores.</p>
                    <form className="mt-4 grid gap-2 md:grid-cols-3" onSubmit={applyFilters}>
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
                        <button type="submit" className="rounded-lg border border-slate-300 px-3 text-sm">Filter</button>
                    </form>
                </section>

                <div className="overflow-x-auto rounded-xl border bg-white">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Period</th>
                                <th className="px-3 py-2">Type</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">Run</th>
                                <th className="px-3 py-2">Counts</th>
                                <th className="px-3 py-2">Generated</th>
                                <th className="px-3 py-2">Error</th>
                                <th className="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {reviews.map((review) => (
                                <tr key={review.id} className="border-t">
                                    <td className="px-3 py-2">{review.period_start} → {review.period_end}</td>
                                    <td className="px-3 py-2">{review.review_type}</td>
                                    <td className="px-3 py-2">{review.status}</td>
                                    <td className="px-3 py-2 font-mono text-xs">{review.automation_run_id || '—'}</td>
                                    <td className="px-3 py-2 text-xs">
                                        {review.snapshot?.commitments}/{review.snapshot?.meetings}/{review.snapshot?.findings}
                                    </td>
                                    <td className="px-3 py-2">{review.generated_at || '—'}</td>
                                    <td className="px-3 py-2 text-rose-700">{review.safe_error || '—'}</td>
                                    <td className="px-3 py-2">
                                        <button
                                            type="button"
                                            className="rounded-lg border px-2 py-1 text-xs"
                                            onClick={() => router.post(route('leadership-reviews.regenerate', review.id))}
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
