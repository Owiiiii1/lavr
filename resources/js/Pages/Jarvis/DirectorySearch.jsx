import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, usePage } from '@inertiajs/react';

export default function DirectorySearch() {
    const { t } = useTranslation();
    const { query = '', results = { people: [], organizations: [], projects: [] } } = usePage().props;
    const empty =
        (results.people || []).length === 0
        && (results.organizations || []).length === 0
        && (results.projects || []).length === 0;

    return (
        <LavrAppShell>
            <Head title={t('search.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">{t('search.title')}</h1>
                <form
                    className="mt-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(route('jarvis.search.show'), { q: event.target.q.value }, { replace: true });
                    }}
                >
                    <input
                        name="q"
                        defaultValue={query}
                        placeholder={t('search.placeholder')}
                        className="min-h-12 w-full rounded-2xl border border-white/10 bg-white/5 px-4 text-sm text-white"
                    />
                </form>

                {query === '' ? (
                    <p className="mt-8 text-sm text-slate-400">{t('search.hint')}</p>
                ) : empty ? (
                    <p className="mt-8 text-sm text-slate-400">{t('search.empty')}</p>
                ) : (
                    <div className="mt-6 space-y-6">
                        <ResultGroup title={t('search.people')} items={results.people} href={(item) => route('jarvis.people.show', item.id)} label={(item) => item.display_name} />
                        <ResultGroup title={t('search.organizations')} items={results.organizations} href={(item) => route('jarvis.organizations.show', item.id)} label={(item) => item.name} />
                        <ResultGroup title={t('search.projects')} items={results.projects} href={(item) => route('jarvis.workspace.projects.show', item.id)} label={(item) => item.name} />
                    </div>
                )}
            </div>
        </LavrAppShell>
    );
}

function ResultGroup({ title, items = [], href, label }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section>
            <h2 className="text-xs uppercase tracking-[0.14em] text-slate-500">{title}</h2>
            <ul className="mt-2 space-y-2">
                {items.map((item) => (
                    <li key={item.id}>
                        <Link href={href(item)} className="block min-h-12 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            {label(item)}
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
