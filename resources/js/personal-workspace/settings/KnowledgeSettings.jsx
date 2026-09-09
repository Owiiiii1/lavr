import { useEffect, useState } from 'react';
import SettingsCard from '@/personal-workspace/settings/SettingsCard';
import { workspaceRoute } from '@/personal-workspace/named';

export default function KnowledgeSettings({ surface }) {
    const [query, setQuery] = useState('');
    const [data, setData] = useState(null);
    const [entity, setEntity] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const controller = new AbortController();
        const params = query.trim() !== '' ? `?q=${encodeURIComponent(query.trim())}` : '';

        setLoading(true);
        fetch(workspaceRoute(surface, 'knowledge.index') + params, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('load_failed');
                }

                return response.json();
            })
            .then((payload) => {
                setData(payload);
                setError(null);
            })
            .catch((caught) => {
                if (caught.name !== 'AbortError') {
                    setError('Не удалось загрузить знания.');
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [surface, query]);

    const openEntity = (id) => {
        fetch(workspaceRoute(surface, 'knowledge.entities.show', id), {
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('not_found');
                }

                return response.json();
            })
            .then((payload) => {
                setEntity(payload);
                setError(null);
            })
            .catch(() => setError('Сущность недоступна.'));
    };

    return (
        <div className="space-y-4">
            <SettingsCard
                title="Knowledge"
                description="Структурированные сущности, связи и события с источниками. Это не Memory: Memory — что LAVR помнит, Knowledge — что существует и как связано."
            >
                <input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Поиск людей, проектов, систем"
                    className="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white placeholder:text-slate-500"
                />
                {loading ? <p className="mt-3 text-xs text-slate-500">Загрузка…</p> : null}
                {error ? <p className="mt-3 text-xs text-rose-300">{error}</p> : null}
                {data ? (
                    <dl className="mt-3 grid grid-cols-3 gap-2 text-xs">
                        <div className="rounded-xl bg-black/20 px-3 py-3">
                            <dt className="text-slate-500">Сущности</dt>
                            <dd className="mt-1 text-lg font-medium text-white">{data.counts?.entities ?? 0}</dd>
                        </div>
                        <div className="rounded-xl bg-black/20 px-3 py-3">
                            <dt className="text-slate-500">People</dt>
                            <dd className="mt-1 text-lg font-medium text-white">{data.counts?.people ?? 0}</dd>
                        </div>
                        <div className="rounded-xl bg-black/20 px-3 py-3">
                            <dt className="text-slate-500">Projects</dt>
                            <dd className="mt-1 text-lg font-medium text-white">{data.counts?.projects ?? 0}</dd>
                        </div>
                    </dl>
                ) : null}
            </SettingsCard>

            <EntityList title="People" items={data?.people} onSelect={openEntity} empty="Пока нет людей в Knowledge." />
            <EntityList title="Projects" items={data?.projects} onSelect={openEntity} empty="Проекты появятся как индекс, когда Project создан или связан." />
            {query.trim() !== '' ? (
                <EntityList title="Результаты" items={data?.results} onSelect={openEntity} empty="Ничего не найдено." />
            ) : null}

            <SettingsCard title="Recent activity" description="Компактная лента, не полный граф.">
                {(data?.recent_activity || []).length === 0 ? (
                    <p className="text-sm text-slate-400">Пока нет событий.</p>
                ) : (
                    <ul className="space-y-2 text-sm text-slate-300">
                        {data.recent_activity.map((event) => (
                            <li key={event.id} className="rounded-xl bg-black/20 px-3 py-2">
                                <div className="text-white">{event.title}</div>
                                <div className="mt-0.5 text-[11px] text-slate-500">
                                    {event.type}
                                    {event.occurred_at ? ` · ${new Date(event.occurred_at).toLocaleString()}` : ''}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </SettingsCard>

            {entity ? (
                <SettingsCard title={entity.name} description={`${entity.type}${entity.summary ? ` · ${entity.summary}` : ''}`}>
                    <p className="text-xs text-slate-400">
                        Источников: {entity.sources_count ?? 0}
                        {entity.project ? ` · Project: ${entity.project.name}` : ''}
                    </p>
                    {(entity.aliases || []).length > 0 ? (
                        <p className="mt-2 text-xs text-slate-400">Алиасы: {entity.aliases.join(', ')}</p>
                    ) : null}
                    <h4 className="mt-3 text-xs font-semibold text-slate-300">Relationships</h4>
                    <ul className="mt-1 space-y-1 text-sm text-slate-300">
                        {(entity.relationships || []).map((relation) => (
                            <li key={relation.id}>
                                {relation.type} {relation.other?.name ?? ''}
                            </li>
                        ))}
                    </ul>
                    <h4 className="mt-3 text-xs font-semibold text-slate-300">Timeline</h4>
                    <ul className="mt-1 space-y-1 text-sm text-slate-300">
                        {(entity.timeline || []).map((event) => (
                            <li key={event.id}>{event.title}</li>
                        ))}
                    </ul>
                    {entity.synthesis ? (
                        <>
                            <h4 className="mt-3 text-xs font-semibold text-slate-300">Сейчас</h4>
                            {(entity.synthesis.blockers || []).length > 0 ? (
                                <p className="mt-1 text-sm text-amber-200">
                                    Блокеры: {entity.synthesis.blockers.map((item) => item.title).join('; ')}
                                </p>
                            ) : null}
                            {(entity.synthesis.waiting_for || []).length > 0 ? (
                                <p className="mt-1 text-sm text-slate-300">
                                    Жду: {entity.synthesis.waiting_for.map((item) => item.title).join('; ')}
                                </p>
                            ) : null}
                            {(entity.synthesis.commitments || []).length > 0 ? (
                                <p className="mt-1 text-sm text-slate-300">
                                    Обязательства: {entity.synthesis.commitments.map((item) => item.title).join('; ')}
                                </p>
                            ) : null}
                            {(entity.synthesis.open_loops || []).length > 0 ? (
                                <p className="mt-1 text-sm text-slate-300">
                                    Open loops: {entity.synthesis.open_loops.map((item) => item.title).join('; ')}
                                </p>
                            ) : null}
                            {(entity.synthesis.people || []).length > 0 ? (
                                <p className="mt-1 text-sm text-slate-300">
                                    Люди: {entity.synthesis.people.map((item) => item.title).join('; ')}
                                </p>
                            ) : null}
                            {(entity.synthesis.open_work || []).length > 0 ? (
                                <p className="mt-1 text-sm text-slate-300">
                                    Открытая работа: {entity.synthesis.open_work.map((item) => item.title).join('; ')}
                                </p>
                            ) : null}
                            {entity.synthesis.person?.last_activity ? (
                                <p className="mt-1 text-xs text-slate-500">
                                    Последняя активность:{' '}
                                    {entity.synthesis.person.last_activity.at
                                        ? new Date(entity.synthesis.person.last_activity.at).toLocaleString()
                                        : 'неизвестно'}
                                </p>
                            ) : null}
                        </>
                    ) : null}
                </SettingsCard>
            ) : null}
        </div>
    );
}

function EntityList({ title, items, onSelect, empty }) {
    const rows = items || [];

    return (
        <SettingsCard title={title}>
            {rows.length === 0 ? (
                <p className="text-sm text-slate-400">{empty}</p>
            ) : (
                <ul className="space-y-1">
                    {rows.map((item) => (
                        <li key={item.id}>
                            <button
                                type="button"
                                onClick={() => onSelect(item.id)}
                                className="w-full rounded-xl px-3 py-2 text-left text-sm text-slate-200 hover:bg-white/5"
                            >
                                <span className="text-white">{item.name}</span>
                                <span className="ml-2 text-[11px] text-slate-500">{item.type}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </SettingsCard>
    );
}
