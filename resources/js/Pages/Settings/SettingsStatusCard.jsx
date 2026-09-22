import { ChevronRight } from 'lucide-react';
import { badgeClass, dotClass, stateLabel, OFF } from './settingsStatus';

export default function SettingsStatusCard({ title, hint, status, t, onOpen }) {
    const state = status?.state ?? OFF;
    const rows = status?.rows ?? [];

    return (
        <button
            type="button"
            onClick={onOpen}
            className="group flex h-full flex-col rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4 text-left transition hover:border-indigo-300 hover:shadow-sm"
        >
            <div className="flex items-start justify-between gap-3">
                <span className="flex items-center gap-2">
                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${dotClass(state)}`} aria-hidden="true" />
                    <span className="text-base font-semibold text-slate-900">{title}</span>
                </span>
                <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${badgeClass(state)}`}>
                    {stateLabel(t, state)}
                </span>
            </div>

            <span className="mt-2 block text-sm text-slate-600">{hint}</span>

            {status?.detail ? (
                <span className="mt-2 block truncate text-sm font-medium text-slate-800">{status.detail}</span>
            ) : null}

            {rows.length > 0 ? (
                <span className="mt-3 block space-y-1 border-t border-[#E6DCC8] pt-3 text-xs">
                    {rows.map((row) => (
                        <span key={row.label} className="flex items-center justify-between gap-3">
                            <span className="text-slate-500">{row.label}</span>
                            <span className="flex min-w-0 items-center gap-1.5 font-medium text-slate-700">
                                {row.state ? (
                                    <span
                                        className={`h-1.5 w-1.5 shrink-0 rounded-full ${dotClass(row.state)}`}
                                        aria-hidden="true"
                                    />
                                ) : null}
                                <span className="truncate">{row.value}</span>
                            </span>
                        </span>
                    ))}
                </span>
            ) : null}

            {status?.error ? <span className="mt-2 block text-xs text-red-700">{status.error}</span> : null}

            <span className="mt-auto flex items-center gap-1 pt-3 text-sm font-medium text-indigo-700">
                {t.open}
                <ChevronRight className="h-4 w-4" />
            </span>
        </button>
    );
}
