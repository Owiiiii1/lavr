import { useCallback, useEffect, useState } from 'react';
import SettingsCard from '@/personal-workspace/settings/SettingsCard';
import { workspaceRoute } from '@/personal-workspace/named';
import { useTranslation } from '@/locales/useTranslation';

const TABS = ['overview', 'profile', 'business', 'goals', 'rules', 'development', 'review', 'sources'];

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

async function readJson(response) {
    if (!response.ok) {
        throw new Error('request_failed');
    }

    return response.json();
}

export default function OwnerContextSettings({ surface }) {
    const { t } = useTranslation();
    const [tab, setTab] = useState('overview');
    const [filters, setFilters] = useState({ q: '', fact_class: '', status: '', sensitivity: '', category: '' });
    const [payload, setPayload] = useState(null);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState([]);
    const [manual, setManual] = useState({
        value: '',
        category: 'ceo_operating_rule',
        fact_class: 'current',
        scope_type: 'owner',
        sensitivity: 'normal',
    });

    const load = useCallback(async () => {
        const params = new URLSearchParams({ tab });
        Object.entries(filters).forEach(([key, value]) => {
            if (value) {
                params.set(key, value);
            }
        });
        const response = await fetch(`${workspaceRoute(surface, 'owner-context.index')}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        setPayload(await readJson(response));
        setError(null);
    }, [surface, tab, filters]);

    useEffect(() => {
        const handle = setTimeout(() => {
            load().catch(() => setError(t('settings.ownerContext.loadError')));
        }, 150);

        return () => clearTimeout(handle);
    }, [load, t]);

    const post = async (name, params, body, isForm = false) => {
        const response = await fetch(workspaceRoute(surface, name, params), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            },
            body: isForm ? body : JSON.stringify(body || {}),
        });
        await readJson(response);
        setSelected([]);
        await load();
    };

    const importFile = async (event) => {
        event.preventDefault();
        const form = new FormData(event.target);
        await post('owner-context.import', undefined, form, true);
        event.target.reset();
    };

    const counts = payload?.counts || {};
    const items = payload?.items || [];
    const sources = payload?.sources || [];

    return (
        <div className="space-y-4">
            <SettingsCard title={t('settings.ownerContext.title')} description={t('settings.ownerContext.intro')}>
                <div className="flex flex-wrap gap-2">
                    {TABS.map((key) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={`rounded-full px-3 py-1 text-xs ${tab === key ? 'bg-white text-slate-900' : 'bg-black/20 text-slate-200'}`}
                        >
                            {t(`settings.ownerContext.tabs.${key}`)}
                        </button>
                    ))}
                </div>
                <dl className="mt-3 grid grid-cols-2 gap-2 text-xs md:grid-cols-3">
                    {[
                        ['accepted', counts.accepted],
                        ['candidates', counts.candidates],
                        ['needsReview', counts.needs_review],
                        ['historical', counts.historical],
                        ['private', counts.private_or_restricted],
                        ['sources', counts.sources],
                    ].map(([key, value]) => (
                        <div key={key} className="rounded-xl bg-black/20 px-3 py-3">
                            <dt className="text-slate-300">{t(`settings.ownerContext.counts.${key}`)}</dt>
                            <dd className="mt-1 text-lg font-medium text-white">{value ?? 0}</dd>
                        </div>
                    ))}
                </dl>
                {error ? <p className="mt-3 text-xs text-rose-300">{error}</p> : null}
            </SettingsCard>

            <SettingsCard title={t('settings.ownerContext.importTitle')}>
                <form className="grid gap-2" onSubmit={(event) => importFile(event).catch(() => setError(t('settings.ownerContext.loadError')))}>
                    <input name="name" required placeholder={t('settings.ownerContext.sourceName')} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                    <input name="source_date" type="date" className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                    <input name="file" type="file" accept=".txt,.md,.markdown,text/plain,text/markdown" required className="text-xs text-slate-200" />
                    <button type="submit" className="rounded-xl bg-white px-3 py-2 text-sm font-medium text-slate-900">{t('settings.ownerContext.import')}</button>
                </form>
            </SettingsCard>

            {tab !== 'sources' ? (
                <SettingsCard title={t('settings.ownerContext.items')}>
                    <div className="mb-3 grid gap-2 md:grid-cols-4">
                        <input value={filters.q} onChange={(event) => setFilters({ ...filters, q: event.target.value })} placeholder={t('settings.ownerContext.search')} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" />
                        <select value={filters.fact_class} onChange={(event) => setFilters({ ...filters, fact_class: event.target.value })} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            <option value="">{t('settings.ownerContext.anyFact')}</option>
                            {['fact', 'current', 'historical', 'analysis', 'to_verify'].map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                        <select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            <option value="">{t('settings.ownerContext.anyStatus')}</option>
                            {['candidate', 'accepted', 'needs_review', 'rejected', 'superseded'].map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                        <select value={filters.sensitivity} onChange={(event) => setFilters({ ...filters, sensitivity: event.target.value })} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            <option value="">{t('settings.ownerContext.anySensitivity')}</option>
                            {['normal', 'private', 'restricted'].map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                    </div>
                    <div className="mb-3 flex flex-wrap gap-2">
                        <button type="button" className="rounded-lg bg-white/10 px-3 py-1 text-xs text-white" onClick={() => post('owner-context.accept-safe', undefined, { ids: selected }).catch(() => setError(t('settings.ownerContext.loadError')))}>{t('settings.ownerContext.acceptSafe')}</button>
                        <button type="button" className="rounded-lg bg-white/10 px-3 py-1 text-xs text-white" onClick={() => selected.length && post('owner-context.reject-selected', undefined, { ids: selected }).catch(() => setError(t('settings.ownerContext.loadError')))}>{t('settings.ownerContext.rejectSelected')}</button>
                    </div>
                    <ul className="space-y-3">
                        {items.map((item) => (
                            <li key={item.id} className="rounded-xl border border-white/10 p-3 text-sm">
                                <label className="flex items-start gap-2">
                                    <input type="checkbox" checked={selected.includes(item.id)} onChange={(event) => setSelected(event.target.checked ? [...selected, item.id] : selected.filter((id) => id !== item.id))} />
                                    <span>
                                        <span className="font-medium text-white">{item.value}</span>
                                        <span className="mt-1 block text-xs text-slate-300">{item.fact_class} · {item.category} · {item.scope_type}{item.scope_label ? ` · ${item.scope_label}` : ''} · {item.status} · {item.sensitivity}{item.confidence != null ? ` · ${item.confidence}` : ''}</span>
                                        {item.evidence_excerpt ? <span className="mt-1 block text-xs text-slate-400">{item.evidence_excerpt}</span> : null}
                                    </span>
                                </label>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <button type="button" className="text-xs text-slate-200 underline" onClick={() => post('owner-context.items.accept', item.id).catch(() => setError(t('settings.ownerContext.loadError')))}>{t('settings.ownerContext.accept')}</button>
                                    <button type="button" className="text-xs text-slate-200 underline" onClick={() => post('owner-context.items.reject', item.id).catch(() => setError(t('settings.ownerContext.loadError')))}>{t('settings.ownerContext.reject')}</button>
                                    <button type="button" className="text-xs text-slate-200 underline" onClick={() => post('owner-context.items.needs-review', item.id).catch(() => setError(t('settings.ownerContext.loadError')))}>{t('settings.ownerContext.needsReviewAction')}</button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </SettingsCard>
            ) : (
                <SettingsCard title={t('settings.ownerContext.tabs.sources')}>
                    <ul className="space-y-3 text-sm">
                        {sources.map((source) => (
                            <li key={source.id} className="rounded-xl border border-white/10 p-3">
                                <p className="font-medium text-white">{source.name}</p>
                                <p className="mt-1 text-xs text-slate-300">{source.status} · {source.source_date || '—'} · {t('settings.ownerContext.extracted')} {source.extracted} · {t('settings.ownerContext.counts.accepted')} {source.accepted} · {t('settings.ownerContext.counts.needsReview')} {source.needs_review}</p>
                                {source.total_chunks ? (
                                    <p className="mt-1 text-xs text-slate-400">
                                        {source.processing === 'partial'
                                            ? t('settings.ownerContext.partialChunks', { processed: source.processed_chunks ?? 0, total: source.total_chunks })
                                            : t('settings.ownerContext.chunks', { processed: source.processed_chunks ?? 0, total: source.total_chunks })}
                                    </p>
                                ) : null}
                                {source.result ? <p className="mt-1 text-xs text-slate-400">{source.result.extracted} / {source.result.accepted} / {source.result.linked} / {source.result.historical} / {source.result.needs_review} / {source.result.private_or_restricted}</p> : null}
                                <div className="mt-2 flex gap-3">
                                    <button type="button" className="text-xs text-slate-200 underline" onClick={() => { setTab('overview'); setFilters({ ...filters, q: '' }); }}>{t('settings.ownerContext.viewItems')}</button>
                                    <button type="button" className="text-xs text-slate-200 underline" onClick={() => post('owner-context.archive', source.id).catch(() => setError(t('settings.ownerContext.loadError')))}>{t('settings.ownerContext.archive')}</button>
                                    {source.status === 'failed' || source.status === 'partial' ? (
                                        <button type="button" className="text-xs text-slate-200 underline" onClick={() => post('owner-context.retry', source.id).catch(() => setError(t('settings.ownerContext.loadError')))}>{t('settings.ownerContext.retry')}</button>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                </SettingsCard>
            )}

            <SettingsCard title={t('settings.ownerContext.manualTitle')}>
                <form className="grid gap-2" onSubmit={(event) => { event.preventDefault(); post('owner-context.items.store', undefined, manual).catch(() => setError(t('settings.ownerContext.loadError'))); }}>
                    <textarea value={manual.value} onChange={(event) => setManual({ ...manual, value: event.target.value })} required minLength={8} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white" placeholder={t('settings.ownerContext.value')} />
                    <div className="grid gap-2 md:grid-cols-2">
                        <select value={manual.category} onChange={(event) => setManual({ ...manual, category: event.target.value })} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            {['identity', 'communication', 'ceo_goal', 'ceo_operating_rule', 'ceo_development', 'business_context', 'business_rule', 'priority', 'role_context', 'personal_constraint', 'other'].map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                        <select value={manual.fact_class} onChange={(event) => setManual({ ...manual, fact_class: event.target.value })} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            {['fact', 'current', 'historical', 'analysis', 'to_verify'].map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                        <select value={manual.scope_type} onChange={(event) => setManual({ ...manual, scope_type: event.target.value })} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            {['owner', 'business', 'organization', 'project', 'person'].map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                        <select value={manual.sensitivity} onChange={(event) => setManual({ ...manual, sensitivity: event.target.value })} className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                            {['normal', 'private', 'restricted'].map((value) => <option key={value} value={value}>{value}</option>)}
                        </select>
                    </div>
                    <button type="submit" className="rounded-xl bg-white px-3 py-2 text-sm font-medium text-slate-900">{t('settings.ownerContext.add')}</button>
                </form>
            </SettingsCard>
        </div>
    );
}
