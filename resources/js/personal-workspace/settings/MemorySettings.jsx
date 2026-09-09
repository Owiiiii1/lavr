import SettingsCard from '@/personal-workspace/settings/SettingsCard';

function formatWhen(iso) {
    if (!iso) {
        return 'ещё не было';
    }

    try {
        return new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
}

export default function MemorySettings({ memory }) {
    const summary = memory || {};

    return (
        <div className="space-y-4">
            <SettingsCard
                title="Память"
                description="LAVR запоминает устойчивые факты из ваших разговоров. Это не настройки личности и не сырые таблицы."
            >
                <dl className="grid grid-cols-2 gap-2 text-xs">
                    <div className="rounded-xl bg-black/20 px-3 py-3">
                        <dt className="text-slate-500">Факты</dt>
                        <dd className="mt-1 text-lg font-medium text-white">{summary.facts_count ?? 0}</dd>
                    </div>
                    <div className="rounded-xl bg-black/20 px-3 py-3">
                        <dt className="text-slate-500">Темы</dt>
                        <dd className="mt-1 text-lg font-medium text-white">{summary.topics_count ?? 0}</dd>
                    </div>
                </dl>
                <p className="mt-3 text-xs text-slate-400">
                    Последний анализ: {formatWhen(summary.last_analysis_at)}
                </p>
                {(summary.facts_count ?? 0) === 0 && (summary.topics_count ?? 0) === 0 ? (
                    <p className="mt-3 text-sm text-slate-400">
                        Пока пусто. Память наполняется из разговоров, когда появляется что-то устойчивое.
                    </p>
                ) : (
                    <p className="mt-3 text-sm text-slate-400">
                        Управление отдельными фактами — через чат («забудь…», «запомни…»).
                    </p>
                )}
            </SettingsCard>
        </div>
    );
}
