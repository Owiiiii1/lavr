import PanelSection from '@/personal-workspace/components/PanelSection';
import PanelShell from '@/personal-workspace/components/PanelShell';
import WorkspaceCard from '@/personal-workspace/components/WorkspaceCard';
import { workspaceRoute } from '@/personal-workspace/named';
import { ChevronDown, ChevronRight, Eye, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function applyPanel(payload, setters) {
    setters.setItems(payload.items || []);
    setters.setRecent(payload.recent || []);
    setters.onCountChange?.(Number(payload.active_count || 0));
}

function WatcherCard({ watcher, busyId, onPause, onResume, onCancel }) {
    const closed = ['completed', 'cancelled', 'failed'].includes(watcher.status);

    return (
        <WorkspaceCard
            title={watcher.description || watcher.name}
            secondary={watcher.last_result_label || watcher.state_label}
            secondaryTone={watcher.problem_label ? 'alert' : 'muted'}
            meta={[watcher.linked?.project, watcher.linked?.entity, watcher.state_label]}
            problem={watcher.problem_label}
            badge={watcher.badge}
            muted={closed}
            actions={[
                watcher.pausable ? { label: 'Приостановить', onSelect: () => onPause(watcher) } : null,
                watcher.resumable ? { label: 'Возобновить', onSelect: () => onResume(watcher) } : null,
                watcher.cancellable
                    ? { label: 'Отменить', tone: 'danger', disabled: busyId === watcher.id, onSelect: () => onCancel(watcher) }
                    : null,
            ].filter(Boolean)}
        />
    );
}

export default function WatchersPanel({ open, surface, refreshToken = 0, onClose, onCountChange, onDataChange, onCreateInChat }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [items, setItems] = useState([]);
    const [recent, setRecent] = useState([]);
    const [busyId, setBusyId] = useState(null);
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState('');
    const [triggerType, setTriggerType] = useState('task_state');
    const [conditionType, setConditionType] = useState('overdue_by');
    const [reactionType, setReactionType] = useState('notify');
    const [mode, setMode] = useState('one_shot');
    const [taskId, setTaskId] = useState('');
    const [sourceFilter, setSourceFilter] = useState('');
    const [cooldown, setCooldown] = useState('3600');

    const setters = { setItems, setRecent, onCountChange };

    const load = () => {
        setLoading(true);
        setError('');

        return fetch(workspaceRoute(surface, 'watchers.index'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось загрузить автоматизации.');
                }
                applyPanel(payload, setters);
            })
            .catch((caught) => setError(caught.message || 'Не удалось загрузить автоматизации.'))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        load();

        return undefined;
    }, [open, surface, refreshToken]);

    const mutate = async (url, options, failure) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            ...options,
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || failure);
        }

        applyPanel(payload, setters);
        onDataChange?.();
        return payload;
    };

    const createWatcher = async (event) => {
        event.preventDefault();
        setBusyId('create');
        setError('');

        const source = {};
        if (sourceFilter.trim()) {
            if (triggerType === 'gmail_message') {
                source.query = sourceFilter.trim();
            } else if (triggerType === 'calendar_event') {
                source.event_id = sourceFilter.trim();
            }
        }

        try {
            await mutate(workspaceRoute(surface, 'watchers.store'), {
                method: 'POST',
                body: JSON.stringify({
                    name,
                    trigger_type: triggerType,
                    condition_type: conditionType,
                    reaction_type: reactionType,
                    mode,
                    cooldown_seconds: Number(cooldown) || 0,
                    task_id: taskId ? Number(taskId) : undefined,
                    source,
                }),
            }, 'Не удалось создать автоматизацию.');
            setName('');
            setTaskId('');
            setSourceFilter('');
            setCreating(false);
        } catch (caught) {
            setError(caught.message || 'Не удалось создать автоматизацию.');
        } finally {
            setBusyId(null);
        }
    };

    const runAction = async (watcher, action, failure) => {
        setBusyId(watcher.id);
        setError('');
        try {
            await mutate(workspaceRoute(surface, `watchers.${action}`, watcher.id), {
                method: 'POST',
            }, failure);
        } catch (caught) {
            setError(caught.message || failure);
        } finally {
            setBusyId(null);
        }
    };

    if (!open) {
        return null;
    }

    const toolbar = (
        <button
            type="button"
            onClick={onCreateInChat}
            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-violet-500/90 px-3 py-2 text-sm font-medium text-white hover:bg-violet-400"
        >
            <Plus className="h-4 w-4" />
            Создать через чат
        </button>
    );

    return (
        <PanelShell icon={Eye} iconClassName="text-violet-300" title="Автоматизации" onClose={onClose} toolbar={toolbar} loading={loading} error={error}>
            <PanelSection title="Слежу за этим" count={items.length} empty="Пока ничего не отслеживается.">
                {items.map((watcher) => (
                    <WatcherCard
                        key={watcher.id}
                        watcher={watcher}
                        busyId={busyId}
                        onPause={(item) => runAction(item, 'pause', 'Не удалось приостановить.')}
                        onResume={(item) => runAction(item, 'resume', 'Не удалось возобновить.')}
                        onCancel={(item) => runAction(item, 'cancel', 'Не удалось отменить.')}
                    />
                ))}
            </PanelSection>

            {recent.length > 0 ? (
                <PanelSection title="Недавние срабатывания" count={recent.length}>
                    {recent.map((row) => (
                        <li key={row.id} className="rounded-lg border border-white/5 bg-black/10 px-3 py-2 text-[11px] text-slate-400">
                            {[row.detected_label, row.summary].filter(Boolean).join(' · ')}
                        </li>
                    ))}
                </PanelSection>
            ) : null}

            <section>
                <button
                    type="button"
                    aria-expanded={creating}
                    onClick={() => setCreating((current) => !current)}
                    className="flex items-center gap-1 rounded-lg py-1 text-xs text-slate-400 hover:text-white"
                >
                    {creating ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                    Расширенные настройки
                </button>
                {creating ? (
                    <form onSubmit={createWatcher} className="mt-2 space-y-2 rounded-xl border border-white/10 bg-black/20 p-3">
                        <p className="text-[11px] text-slate-500">
                            Ручная настройка для точных сценариев. Обычно проще описать задачу словами в чате.
                        </p>
                        <input
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Название"
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-sm"
                            required
                        />
                        <select value={triggerType} onChange={(event) => setTriggerType(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                            <option value="task_state">Задача</option>
                            <option value="time_condition">Срок / время</option>
                            <option value="knowledge_event">Событие знания</option>
                            <option value="gmail_message">Gmail</option>
                            <option value="calendar_event">Календарь</option>
                        </select>
                        <select value={conditionType} onChange={(event) => setConditionType(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                            <option value="overdue_by">Просрочена</option>
                            <option value="deadline_within">До дедлайна</option>
                            <option value="new_item">Новый элемент</option>
                            <option value="thread_received_reply">Ответ в треде</option>
                            <option value="calendar_changed">Календарь изменился</option>
                            <option value="entity_event_type">Событие сущности</option>
                        </select>
                        <select value={reactionType} onChange={(event) => setReactionType(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                            <option value="notify">Уведомить</option>
                            <option value="create_reminder">Создать напоминание</option>
                            <option value="create_task">Создать задачу</option>
                            <option value="run_internal_analysis">Разобрать и сказать</option>
                            <option value="propose_action">Предложить действие</option>
                        </select>
                        <select value={mode} onChange={(event) => setMode(event.target.value)} className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs">
                            <option value="one_shot">Один раз</option>
                            <option value="recurring">Повторять</option>
                        </select>
                        <input
                            value={taskId}
                            onChange={(event) => setTaskId(event.target.value)}
                            placeholder="ID задачи (если следим за задачей)"
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs"
                        />
                        <input
                            value={sourceFilter}
                            onChange={(event) => setSourceFilter(event.target.value)}
                            placeholder="Query / sender / repo / event id"
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs"
                        />
                        <input
                            value={cooldown}
                            onChange={(event) => setCooldown(event.target.value)}
                            placeholder="Cooldown, сек"
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-xs"
                        />
                        <button
                            type="submit"
                            disabled={busyId === 'create'}
                            className="rounded-lg bg-violet-500 px-3 py-1 text-xs font-medium text-white hover:bg-violet-400 disabled:opacity-50"
                        >
                            Сохранить
                        </button>
                    </form>
                ) : null}
            </section>
        </PanelShell>
    );
}
