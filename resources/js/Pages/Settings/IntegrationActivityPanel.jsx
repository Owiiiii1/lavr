import { usePage } from '@inertiajs/react';

export default function IntegrationActivityPanel() {
    const { integrations = {}, locale = 'en' } = usePage().props;
    const executions = integrations.recent_executions ?? [];

    const text = {
        en: {
            title: 'Recent tool executions',
            hint: 'Safe audit trail only: time, tool, provider, status, duration, error code. No arguments, tokens, or result bodies.',
            empty: 'No tool executions yet.',
            time: 'Time',
            tool: 'Tool',
            provider: 'Provider',
            status: 'Status',
            duration: 'Duration',
            error: 'Error',
        },
        ru: {
            title: 'Недавние выполнения',
            hint: 'Только безопасный журнал: время, инструмент, провайдер, статус, длительность и код ошибки. Без аргументов, токенов и тел результатов.',
            empty: 'Выполнений пока нет.',
            time: 'Время',
            tool: 'Инструмент',
            provider: 'Провайдер',
            status: 'Статус',
            duration: 'Длительность',
            error: 'Ошибка',
        },
        uk: {
            title: 'Недавні виконання',
            hint: 'Лише безпечний журнал: час, інструмент, провайдер, статус, тривалість і код помилки. Без аргументів, токенів і тіл результатів.',
            empty: 'Виконань поки немає.',
            time: 'Час',
            tool: 'Інструмент',
            provider: 'Провайдер',
            status: 'Статус',
            duration: 'Тривалість',
            error: 'Помилка',
        },
    };
    const t = text[locale] ?? text.en;

    return (
        <section className="rounded-xl border border-[#E6DCC8] bg-white p-4">
            <h2 className="text-base font-semibold text-slate-900">{t.title}</h2>
            <p className="mt-1 text-sm text-slate-600">{t.hint}</p>
            {executions.length === 0 ? (
                <p className="mt-3 text-sm text-slate-600">{t.empty}</p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="py-2 pr-4">{t.time}</th>
                                <th className="py-2 pr-4">{t.tool}</th>
                                <th className="py-2 pr-4">{t.provider}</th>
                                <th className="py-2 pr-4">{t.status}</th>
                                <th className="py-2 pr-4">{t.duration}</th>
                                <th className="py-2">{t.error}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {executions.map((row) => (
                                <tr key={row.id} className="border-t border-slate-100">
                                    <td className="py-2 pr-4 text-slate-600">{row.time ?? '—'}</td>
                                    <td className="py-2 pr-4 font-medium text-slate-800">{row.tool}</td>
                                    <td className="py-2 pr-4 text-slate-600">{row.provider ?? 'core'}</td>
                                    <td className="py-2 pr-4 text-slate-700">{row.status}</td>
                                    <td className="py-2 pr-4 text-slate-600">
                                        {row.duration_ms != null ? `${row.duration_ms} ms` : '—'}
                                    </td>
                                    <td className="py-2 text-slate-600">{row.error_code ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
