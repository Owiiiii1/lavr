import { usePage } from '@inertiajs/react';
import SettingsPanelHeader from './SettingsPanelHeader';
import TelegramPanel from './TelegramPanel';

export default function TelegramSettingsPanel({ t, status }) {
    const { integrations = {} } = usePage().props;
    const groups = integrations.telegram_groups ?? [];

    return (
        <div className="space-y-4">
            <SettingsPanelHeader title={t.tabTelegram} hint={t.hintTelegram} status={status} t={t} />

            <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                <TelegramPanel />
            </section>

            {groups.length > 0 ? (
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <h3 className="mb-3 text-sm font-semibold text-slate-900">{t.telegramGroups}</h3>
                    <ul className="space-y-2 text-sm text-slate-700">
                        {groups.map((group) => (
                            <li key={group.id}>
                                {group.title}
                                {group.monitoring_enabled ? ` · ${t.monitoring}` : ''}
                                {group.last_message_at ? ` · ${group.last_message_at}` : ''}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </div>
    );
}
