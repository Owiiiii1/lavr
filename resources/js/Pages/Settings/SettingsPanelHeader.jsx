import { badgeClass, dotClass, stateLabel, OFF } from './settingsStatus';

export default function SettingsPanelHeader({ title, hint, status, t }) {
    const state = status?.state ?? OFF;
    const rows = status?.rows ?? [];

    return (
        <header className="rounded-xl border border-[#E6DCC8] bg-white p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${dotClass(state)}`} aria-hidden="true" />
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                </div>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${badgeClass(state)}`}>
                    {stateLabel(t, state)}
                </span>
            </div>

            {hint ? <p className="mt-2 max-w-3xl text-sm text-slate-600">{hint}</p> : null}

            {rows.length > 0 ? (
                <ul className="mt-3 flex flex-wrap gap-2">
                    {rows.map((row) => (
                        <li
                            key={row.label}
                            className="flex items-center gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1 text-xs text-slate-600"
                        >
                            {row.state ? (
                                <span
                                    className={`h-1.5 w-1.5 shrink-0 rounded-full ${dotClass(row.state)}`}
                                    aria-hidden="true"
                                />
                            ) : null}
                            <span>{row.label}:</span>
                            <span className="font-semibold text-slate-800">{row.value}</span>
                        </li>
                    ))}
                </ul>
            ) : null}

            {status?.error ? <p className="mt-2 text-xs text-red-700">{status.error}</p> : null}
        </header>
    );
}
