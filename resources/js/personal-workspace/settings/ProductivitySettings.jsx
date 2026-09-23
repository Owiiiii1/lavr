import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from '@/locales/useTranslation';
import SettingsCard from '@/personal-workspace/settings/SettingsCard';
import { workspaceRoute } from '@/personal-workspace/named';
import { currentPushState, enableReminderPush, pushSupported } from '@/personal-workspace/reminderPush';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export default function ProductivitySettings({ surface, settings, capabilities }) {
    const { t } = useTranslation();
    const productivityForm = useForm({
        daily_brief_enabled: Boolean(settings.productivity?.daily_brief_enabled),
        daily_brief_local_time: settings.productivity?.daily_brief_local_time || '08:00',
        evening_review_enabled: Boolean(settings.productivity?.evening_review_enabled),
        evening_review_local_time: settings.productivity?.evening_review_local_time || '20:00',
        weekly_review_enabled: Boolean(settings.productivity?.weekly_review_enabled),
        weekly_review_weekday: Number(settings.productivity?.weekly_review_weekday || 7),
        weekly_review_local_time: settings.productivity?.weekly_review_local_time || '18:00',
        proactive_enabled: Boolean(settings.productivity?.proactive_enabled),
        operational_alerts_enabled: settings.productivity?.operational_alerts_enabled !== false,
        operational_min_severity: settings.productivity?.operational_min_severity || 'high',
        operational_max_alerts_per_day: settings.productivity?.operational_max_alerts_per_day || 6,
        quiet_hours_start: settings.productivity?.quiet_hours_start || '',
        quiet_hours_end: settings.productivity?.quiet_hours_end || '',
        critical_bypass_quiet_hours: settings.productivity?.critical_bypass_quiet_hours !== false,
        auto_create_reminders: Boolean(settings.productivity?.auto_create_reminders),
        auto_draft_messages: Boolean(settings.productivity?.auto_draft_messages),
        third_party_execute: Boolean(settings.productivity?.third_party_execute),
        morning_brief_enabled: settings.productivity?.morning_brief_enabled !== false,
        morning_brief_local_time: settings.productivity?.morning_brief_local_time || '08:30',
        morning_brief_telegram: settings.productivity?.morning_brief_telegram !== false,
        morning_brief_inbox: settings.productivity?.morning_brief_inbox !== false,
        morning_brief_weekends: Boolean(settings.productivity?.morning_brief_weekends),
        leadership_review_enabled: settings.productivity?.leadership_review_enabled !== false,
        leadership_review_weekday: Number(settings.productivity?.leadership_review_weekday || 1),
        leadership_review_local_time: settings.productivity?.leadership_review_local_time || '09:00',
        leadership_review_telegram: settings.productivity?.leadership_review_telegram !== false,
        leadership_review_inbox: settings.productivity?.leadership_review_inbox !== false,
        auto_generate_leadership_review: settings.productivity?.auto_generate_leadership_review !== false,
        default_review_person_id: settings.productivity?.default_review_person_id || '',
    });
    const [pushState, setPushState] = useState('disabled');
    const [webPushConfigured, setWebPushConfigured] = useState(false);
    const [vapidPublicKey, setVapidPublicKey] = useState('');
    const [pushBusy, setPushBusy] = useState(false);
    const [pushError, setPushError] = useState('');

    useEffect(() => {
        if (!capabilities.reminders) {
            return undefined;
        }

        let cancelled = false;

        fetch(workspaceRoute(surface, 'reminders.push.status'), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!cancelled && response.ok) {
                    setWebPushConfigured(Boolean(payload.configured));
                    setVapidPublicKey(payload.vapid_public_key || '');
                }
            })
            .catch(() => {});

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
    }, [capabilities.reminders, surface]);

    const enablePush = async () => {
        setPushBusy(true);
        setPushError('');

        try {
            const result = await enableReminderPush({
                vapidPublicKey,
                subscribeUrl: workspaceRoute(surface, 'reminders.push.store'),
                csrfToken: csrfToken(),
            });
            setPushState(result.state);
        } catch (caught) {
            setPushError(caught.message || 'Не удалось включить уведомления.');
        } finally {
            setPushBusy(false);
        }
    };

    const pushCopy = {
        enabled: 'Web Push включён в этом браузере.',
        disabled: 'Web Push выключен. Фоновые напоминания придут, если включите уведомления.',
        denied: 'Браузер запретил уведомления. Разрешите их в настройках сайта.',
        unsupported: 'Этот браузер не поддерживает Web Push.',
    }[pushState];

    return (
        <div className="space-y-4">
            {capabilities.tasks ? (
                <SettingsCard
                    title="Сводки и подсказки"
                    description="Выключены по умолчанию. Время считается в вашем часовом поясе. Центры задач и напоминаний остаются на главном экране."
                >
                    <div className="space-y-3 text-sm text-slate-200">
                        <p className="text-sm font-medium text-slate-200">Morning Brief</p>
                        <label className="flex items-center justify-between gap-3">
                            <span>Увімкнено</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.morning_brief_enabled)}
                                onChange={(event) => productivityForm.setData('morning_brief_enabled', event.target.checked)}
                            />
                        </label>
                        <input
                            type="time"
                            value={productivityForm.data.morning_brief_local_time}
                            onChange={(event) => productivityForm.setData('morning_brief_local_time', event.target.value)}
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                        />
                        <label className="flex items-center justify-between gap-3">
                            <span>Telegram</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.morning_brief_telegram)}
                                onChange={(event) => productivityForm.setData('morning_brief_telegram', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>In-app</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.morning_brief_inbox)}
                                onChange={(event) => productivityForm.setData('morning_brief_inbox', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>Вихідні</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.morning_brief_weekends)}
                                onChange={(event) => productivityForm.setData('morning_brief_weekends', event.target.checked)}
                            />
                        </label>
                        <p className="text-sm font-medium text-slate-200">{t('meetings.review.leadership')}</p>
                        <label className="block text-sm">
                            <span>{t('settings.reviewPerson')}</span>
                            <select
                                value={productivityForm.data.default_review_person_id}
                                onChange={(event) => productivityForm.setData('default_review_person_id', event.target.value)}
                                className="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            >
                                <option value="">{t('meetings.review.selectParticipant')}</option>
                                {(settings.review_people || []).map((person) => (
                                    <option key={person.id} value={person.id}>{person.display_name}</option>
                                ))}
                            </select>
                            <span className="mt-1 block text-xs text-slate-400">{t('settings.reviewPersonHint')}</span>
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>{t('settings.reviewAuto')}</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.auto_generate_leadership_review)}
                                onChange={(event) => productivityForm.setData('auto_generate_leadership_review', event.target.checked)}
                            />
                        </label>
                        <p className="text-sm font-medium text-slate-200">Leadership Review</p>
                        <label className="flex items-center justify-between gap-3">
                            <span>Увімкнено</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.leadership_review_enabled)}
                                onChange={(event) => productivityForm.setData('leadership_review_enabled', event.target.checked)}
                            />
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <select
                                value={productivityForm.data.leadership_review_weekday}
                                onChange={(event) => productivityForm.setData('leadership_review_weekday', Number(event.target.value))}
                                className="rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            >
                                <option value={1}>Пн</option>
                                <option value={2}>Вт</option>
                                <option value={3}>Ср</option>
                                <option value={4}>Чт</option>
                                <option value={5}>Пт</option>
                                <option value={6}>Сб</option>
                                <option value={7}>Нд</option>
                            </select>
                            <input
                                type="time"
                                value={productivityForm.data.leadership_review_local_time}
                                onChange={(event) => productivityForm.setData('leadership_review_local_time', event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            />
                        </div>
                        <label className="flex items-center justify-between gap-3">
                            <span>Telegram</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.leadership_review_telegram)}
                                onChange={(event) => productivityForm.setData('leadership_review_telegram', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>In-app</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.leadership_review_inbox)}
                                onChange={(event) => productivityForm.setData('leadership_review_inbox', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>Daily Brief</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.daily_brief_enabled)}
                                onChange={(event) => productivityForm.setData('daily_brief_enabled', event.target.checked)}
                            />
                        </label>
                        <input
                            type="time"
                            value={productivityForm.data.daily_brief_local_time}
                            onChange={(event) => productivityForm.setData('daily_brief_local_time', event.target.value)}
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                        />
                        <label className="flex items-center justify-between gap-3">
                            <span>Evening Review</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.evening_review_enabled)}
                                onChange={(event) => productivityForm.setData('evening_review_enabled', event.target.checked)}
                            />
                        </label>
                        <input
                            type="time"
                            value={productivityForm.data.evening_review_local_time}
                            onChange={(event) => productivityForm.setData('evening_review_local_time', event.target.value)}
                            className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                        />
                        <label className="flex items-center justify-between gap-3">
                            <span>Weekly Review</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.weekly_review_enabled)}
                                onChange={(event) => productivityForm.setData('weekly_review_enabled', event.target.checked)}
                            />
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <select
                                value={productivityForm.data.weekly_review_weekday}
                                onChange={(event) => productivityForm.setData('weekly_review_weekday', Number(event.target.value))}
                                className="rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            >
                                <option value={1}>Пн</option>
                                <option value={2}>Вт</option>
                                <option value={3}>Ср</option>
                                <option value={4}>Чт</option>
                                <option value={5}>Пт</option>
                                <option value={6}>Сб</option>
                                <option value={7}>Вс</option>
                            </select>
                            <input
                                type="time"
                                value={productivityForm.data.weekly_review_local_time}
                                onChange={(event) => productivityForm.setData('weekly_review_local_time', event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            />
                        </div>
                        <label className="flex items-center justify-between gap-3">
                            <span>Proactive suggestions</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.proactive_enabled)}
                                onChange={(event) => productivityForm.setData('proactive_enabled', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>Operational alerts</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.operational_alerts_enabled)}
                                onChange={(event) => productivityForm.setData('operational_alerts_enabled', event.target.checked)}
                            />
                        </label>
                        <label className="block text-sm">
                            Minimum severity
                            <select
                                value={productivityForm.data.operational_min_severity}
                                onChange={(event) => productivityForm.setData('operational_min_severity', event.target.value)}
                                className="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            >
                                <option value="critical">critical</option>
                                <option value="high">high</option>
                                <option value="normal">normal</option>
                                <option value="low">low</option>
                            </select>
                        </label>
                        <label className="block text-sm">
                            Max alerts / day
                            <input
                                type="number"
                                min="1"
                                max="20"
                                value={productivityForm.data.operational_max_alerts_per_day}
                                onChange={(event) => productivityForm.setData('operational_max_alerts_per_day', Number(event.target.value))}
                                className="mt-1 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            />
                        </label>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <input
                                type="time"
                                value={productivityForm.data.quiet_hours_start}
                                onChange={(event) => productivityForm.setData('quiet_hours_start', event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            />
                            <input
                                type="time"
                                value={productivityForm.data.quiet_hours_end}
                                onChange={(event) => productivityForm.setData('quiet_hours_end', event.target.value)}
                                className="w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-slate-100"
                            />
                        </div>
                        <label className="flex items-center justify-between gap-3">
                            <span>Critical may bypass quiet hours</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.critical_bypass_quiet_hours)}
                                onChange={(event) => productivityForm.setData('critical_bypass_quiet_hours', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>Auto-create personal reminders</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.auto_create_reminders)}
                                onChange={(event) => productivityForm.setData('auto_create_reminders', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>Auto-draft messages</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.auto_draft_messages)}
                                onChange={(event) => productivityForm.setData('auto_draft_messages', event.target.checked)}
                            />
                        </label>
                        <label className="flex items-center justify-between gap-3">
                            <span>Third-party execute (off by default)</span>
                            <input
                                type="checkbox"
                                checked={Boolean(productivityForm.data.third_party_execute)}
                                onChange={(event) => productivityForm.setData('third_party_execute', event.target.checked)}
                            />
                        </label>
                        <button
                            type="button"
                            onClick={() => productivityForm.patch(workspaceRoute(surface, 'settings.productivity.update'), { preserveScroll: true })}
                            className="rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs font-medium text-white"
                        >
                            Сохранить
                        </button>
                    </div>
                </SettingsCard>
            ) : null}

            {capabilities.reminders ? (
                <SettingsCard title="Web Push" description="Фоновые напоминания и уведомления на это устройство.">
                    <p className="text-sm text-slate-200">{pushCopy}</p>
                    {pushError ? <p className="mt-2 text-xs text-rose-300">{pushError}</p> : null}
                    {pushState === 'disabled' && webPushConfigured ? (
                        <button
                            type="button"
                            disabled={pushBusy}
                            onClick={enablePush}
                            className="mt-3 rounded-lg bg-sky-500/90 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-400 disabled:opacity-50"
                        >
                            {pushBusy ? 'Включаем…' : 'Включить уведомления'}
                        </button>
                    ) : null}
                </SettingsCard>
            ) : null}
        </div>
    );
}
