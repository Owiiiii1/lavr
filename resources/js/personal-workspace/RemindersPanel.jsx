import ChoiceDialog from '@/personal-workspace/components/ChoiceDialog';
import PanelSection from '@/personal-workspace/components/PanelSection';
import PanelShell from '@/personal-workspace/components/PanelShell';
import WorkspaceCard from '@/personal-workspace/components/WorkspaceCard';
import { workspaceRoute } from '@/personal-workspace/named';
import { currentPushState, enableReminderPush, notificationPermission, pushSupported } from '@/personal-workspace/reminderPush';
import { Bell, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function recurrenceLabel(value) {
    return (
        {
            daily: 'каждый день',
            weekdays: 'по будням',
            weekly: 'еженедельно',
            monthly: 'ежемесячно',
        }[value] || value
    );
}

function applyPanel(payload, setters) {
    setters.setToday(payload.today ?? []);
    setters.setUpcoming(payload.upcoming ?? []);
    setters.setDue(payload.due ?? []);
    setters.setHistory(payload.history ?? []);
    setters.setTelegramConnected(Boolean(payload.telegram_connected));
    setters.setWebPushConfigured(Boolean(payload.web_push_configured));
    setters.setVapidPublicKey(payload.vapid_public_key || '');
    setters.onCountChange?.(
        typeof payload.active_count === 'number'
            ? payload.active_count
            : (payload.active?.length ?? 0),
    );
}

function ReminderCard({ reminder, busyId, onDone, onCancel, onEdit, onSnooze }) {
    const past = reminder.is_occurrence;
    const closed = ['completed', 'cancelled', 'failed'].includes(reminder.status);

    const actions = past
        ? []
        : [
              reminder.editable ? { label: 'Изменить', onSelect: () => onEdit(reminder) } : null,
              reminder.snoozable ? { label: 'Отложить', onSelect: () => onSnooze(reminder) } : null,
              reminder.completable ? { label: 'Отметить выполненным', onSelect: () => onDone(reminder.id) } : null,
              reminder.cancellable ? { label: 'Отменить напоминание', tone: 'danger', onSelect: () => onCancel(reminder.id) } : null,
          ].filter(Boolean);

    // A future reminder needs no primary action: there is nothing to close yet.
    const showDone = !past && reminder.completable && (reminder.is_due || reminder.status === 'delivered');

    return (
        <WorkspaceCard
            title={reminder.text}
            secondary={reminder.schedule_label}
            secondaryTone={reminder.is_due ? 'alert' : 'muted'}
            meta={[
                past || closed ? reminder.status_label : null,
                reminder.task_label,
                reminder.recurrence ? recurrenceLabel(reminder.recurrence) : null,
                reminder.timezone_label,
            ]}
            problem={past ? null : reminder.problem_label}
            muted={past || closed}
            primaryAction={
                showDone
                    ? { label: 'Выполнено', disabled: busyId === reminder.id, onSelect: () => onDone(reminder.id) }
                    : null
            }
            actions={actions}
        />
    );
}

export default function RemindersPanel({
    open,
    surface,
    timezone,
    telegramHint,
    refreshToken = 0,
    onClose,
    onCreateInChat,
    onCountChange,
    onDataChange,
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [today, setToday] = useState([]);
    const [upcoming, setUpcoming] = useState([]);
    const [due, setDue] = useState([]);
    const [history, setHistory] = useState([]);
    const [telegramConnected, setTelegramConnected] = useState(true);
    const [webPushConfigured, setWebPushConfigured] = useState(false);
    const [vapidPublicKey, setVapidPublicKey] = useState('');
    const [pushState, setPushState] = useState('disabled');
    const [pushBusy, setPushBusy] = useState(false);
    const [busyId, setBusyId] = useState(null);
    const [editing, setEditing] = useState(null);
    const [editText, setEditText] = useState('');
    const [editWhen, setEditWhen] = useState('');
    const [editRecurrence, setEditRecurrence] = useState('');
    const [snoozing, setSnoozing] = useState(null);
    const [customWhen, setCustomWhen] = useState('');

    const setters = { setToday, setUpcoming, setDue, setHistory, setTelegramConnected, setWebPushConfigured, setVapidPublicKey, onCountChange };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        let cancelled = false;
        setLoading(true);
        setError('');

        fetch(workspaceRoute(surface, 'reminders.index'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось загрузить напоминания.');
                }
                return payload;
            })
            .then((payload) => {
                if (cancelled) {
                    return;
                }
                applyPanel(payload, setters);
            })
            .catch((caught) => {
                if (!cancelled) {
                    setError(caught.message || 'Не удалось загрузить напоминания.');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        currentPushState()
            .then((state) => {
                if (!cancelled) {
                    setPushState(state);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPushState(pushSupported() ? 'disabled' : 'unsupported');
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open, surface, onCountChange, refreshToken]);

    const mutate = async (url, options, failure) => {
        setError('');
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

    const snoozeReminder = async (id, preset, runAtLocal) => {
        setBusyId(id);
        try {
            await mutate(
                workspaceRoute(surface, 'reminders.snooze', id),
                { method: 'POST', body: JSON.stringify({ preset, run_at_local: runAtLocal || null }) },
                'Не удалось отложить напоминание.',
            );
            setSnoozing(null);
            setCustomWhen('');
        } catch (caught) {
            setError(caught.message || 'Не удалось отложить напоминание.');
        } finally {
            setBusyId(null);
        }
    };

    const completeReminder = async (id) => {
        setBusyId(id);
        try {
            await mutate(workspaceRoute(surface, 'reminders.complete', id), { method: 'POST' }, 'Не удалось отметить выполненным.');
        } catch (caught) {
            setError(caught.message || 'Не удалось отметить выполненным.');
        } finally {
            setBusyId(null);
        }
    };

    const cancelReminder = async (id) => {
        setBusyId(id);
        try {
            await mutate(workspaceRoute(surface, 'reminders.cancel', id), { method: 'POST' }, 'Не удалось отменить напоминание.');
        } catch (caught) {
            setError(caught.message || 'Не удалось отменить напоминание.');
        } finally {
            setBusyId(null);
        }
    };

    const saveEdit = async () => {
        if (!editing) {
            return;
        }

        setBusyId(editing.id);
        try {
            await mutate(
                workspaceRoute(surface, 'reminders.update', editing.id),
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        text: editText,
                        run_at_local: editWhen,
                        timezone: editing.timezone || timezone,
                        recurrence: editRecurrence || null,
                    }),
                },
                'Не удалось сохранить напоминание.',
            );
            setEditing(null);
        } catch (caught) {
            setError(caught.message || 'Не удалось сохранить напоминание.');
        } finally {
            setBusyId(null);
        }
    };

    const enablePush = async () => {
        if (notificationPermission() === 'denied') {
            setPushState('denied');
            return;
        }

        setPushBusy(true);
        setError('');

        try {
            const result = await enableReminderPush({
                vapidPublicKey,
                subscribeUrl: workspaceRoute(surface, 'reminders.push.store'),
                csrfToken: csrfToken(),
            });
            setPushState(result.state);
        } catch (caught) {
            setError(caught.message || 'Не удалось включить уведомления.');
        } finally {
            setPushBusy(false);
        }
    };

    const startEdit = (item) => {
        setEditing(item);
        setEditText(item.text);
        setEditWhen((item.run_at_local || '').slice(0, 19));
        setEditRecurrence(item.recurrence || '');
    };

    if (!open) {
        return null;
    }

    const cardProps = {
        busyId,
        onDone: completeReminder,
        onCancel: cancelReminder,
        onEdit: startEdit,
        onSnooze: (reminder) => setSnoozing(reminder),
    };

    const section = (label, items, empty) => (
        <PanelSection title={label} count={items.length} empty={empty}>
            {items.map((reminder) => (
                <ReminderCard key={reminder.id} reminder={reminder} {...cardProps} />
            ))}
        </PanelSection>
    );

    const toolbar = (
        <button
            type="button"
            onClick={onCreateInChat}
            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-sky-500/90 px-3 py-2 text-sm font-medium text-white hover:bg-sky-400"
        >
            <Plus className="h-4 w-4" />
            Создать в чате
        </button>
    );

    return (
        <>
            <PanelShell icon={Bell} iconClassName="text-white" title="Напоминания" onClose={onClose} toolbar={toolbar} loading={loading} error={error}>
                {pushState === 'denied' ? (
                    <p className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-amber-300">
                        Браузер запретил уведомления. Разрешите их в настройках сайта.
                    </p>
                ) : null}
                {pushState === 'disabled' && webPushConfigured ? (
                    <div className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300">
                        <p>Уведомления в браузере пока выключены.</p>
                        <button
                            type="button"
                            disabled={pushBusy}
                            onClick={enablePush}
                            className="mt-2 rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-400 disabled:opacity-50"
                        >
                            {pushBusy ? 'Включаем…' : 'Включить уведомления'}
                        </button>
                    </div>
                ) : null}
                {telegramConnected ? null : (
                    <p className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300">
                        {telegramHint ||
                            'Telegram не подключён. Напоминание сохранено в LAVR. Подключите Telegram, если нужна доставка ещё и туда.'}
                    </p>
                )}
                {editing ? (
                    <section className="space-y-2 rounded-xl border border-sky-500/30 bg-black/20 p-3">
                        <p className="text-xs text-slate-400">Изменить напоминание</p>
                        <input
                            value={editText}
                            onChange={(event) => setEditText(event.target.value)}
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white"
                        />
                        <input
                            type="datetime-local"
                            value={editWhen.slice(0, 16)}
                            onChange={(event) => setEditWhen(event.target.value)}
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white"
                        />
                        <select
                            value={editRecurrence}
                            onChange={(event) => setEditRecurrence(event.target.value)}
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-white"
                        >
                            <option value="">Без повтора</option>
                            <option value="daily">Каждый день</option>
                            <option value="weekdays">По будням</option>
                            <option value="weekly">Еженедельно</option>
                            <option value="monthly">Ежемесячно</option>
                        </select>
                        <div className="flex gap-2">
                            <button type="button" onClick={saveEdit} className="rounded-lg bg-sky-500 px-3 py-1.5 text-xs text-white">
                                Сохранить
                            </button>
                            <button type="button" onClick={() => setEditing(null)} className="rounded-lg border border-white/10 px-3 py-1.5 text-xs text-slate-300">
                                Отмена
                            </button>
                        </div>
                    </section>
                ) : null}
                {section('Пора сделать', due, 'Ничего не ждёт вашего ответа.')}
                {section('Сегодня', today, 'На сегодня напоминаний нет.')}
                {section('Дальше', upcoming, 'Ближайших напоминаний нет.')}
                {section('История', history, 'История пока пуста.')}
            </PanelShell>
            <ChoiceDialog
                open={Boolean(snoozing)}
                title="Когда напомнить снова?"
                options={[
                    { key: '10m', label: 'Через 10 минут' },
                    { key: '1h', label: 'Через час' },
                    { key: 'tomorrow', label: 'Завтра' },
                ]}
                footer={
                    <div className="space-y-2">
                        <p className="text-[11px] text-slate-400">Выбрать время</p>
                        <input
                            type="datetime-local"
                            value={customWhen}
                            onChange={(event) => setCustomWhen(event.target.value)}
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-xs text-white"
                        />
                        <button
                            type="button"
                            disabled={!customWhen}
                            onClick={() => snoozeReminder(snoozing.id, 'custom', customWhen)}
                            className="w-full rounded-lg bg-amber-500/90 px-3 py-1.5 text-xs text-white disabled:opacity-40"
                        >
                            Отложить до этого времени
                        </button>
                    </div>
                }
                onSelect={(preset) => snoozeReminder(snoozing.id, preset)}
                onCancel={() => setSnoozing(null)}
            />
        </>
    );
}
