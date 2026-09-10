import PanelSection from '@/personal-workspace/components/PanelSection';
import PanelShell from '@/personal-workspace/components/PanelShell';
import WorkspaceCard from '@/personal-workspace/components/WorkspaceCard';
import { workspaceRoute } from '@/personal-workspace/named';
import { FileText, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function applyPanel(payload, setters) {
    setters.setItems(payload.items || []);
    setters.setRecent(payload.recent || []);
    setters.onCountChange?.(Number(payload.active_count || 0));
}

function ReportCard({ report, busyId, onPause, onResume, onCancel }) {
    const closed = report.status === 'cancelled';

    return (
        <WorkspaceCard
            title={report.name}
            secondary={report.last_result_label || report.schedule_label}
            secondaryTone={report.badge ? 'alert' : 'muted'}
            meta={[report.schedule_label, report.source_labels?.join(' · ')]}
            badge={report.badge}
            muted={closed}
            actions={[
                report.pausable ? { label: 'Приостановить', onSelect: () => onPause(report) } : null,
                report.resumable ? { label: 'Возобновить', onSelect: () => onResume(report) } : null,
                report.cancellable
                    ? { label: 'Отменить', tone: 'danger', disabled: busyId === report.id, onSelect: () => onCancel(report) }
                    : null,
            ].filter(Boolean)}
        />
    );
}

export default function ReportsPanel({ open, surface, refreshToken = 0, onClose, onCountChange, onDataChange, onCreateInChat }) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [items, setItems] = useState([]);
    const [recent, setRecent] = useState([]);
    const [busyId, setBusyId] = useState(null);

    const setters = { setItems, setRecent, onCountChange };

    const load = () => {
        setLoading(true);
        setError('');

        return fetch(workspaceRoute(surface, 'reports.index'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(payload.message || 'Не удалось загрузить отчёты.');
                }
                applyPanel(payload, setters);
            })
            .catch((caught) => setError(caught.message || 'Не удалось загрузить отчёты.'))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        load();

        return undefined;
    }, [open, surface, refreshToken]);

    const mutate = async (url, failure) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(payload.message || failure);
        }

        applyPanel(payload, setters);
        onDataChange?.();
        return payload;
    };

    const runAction = async (report, action, failure) => {
        setBusyId(report.id);
        setError('');
        try {
            await mutate(workspaceRoute(surface, `reports.${action}`, report.id), failure);
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
            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-sky-500/90 px-3 py-2 text-sm font-medium text-white hover:bg-sky-400"
        >
            <Plus className="h-4 w-4" />
            Создать через чат
        </button>
    );

    return (
        <PanelShell icon={FileText} title="Отчеты" onClose={onClose} toolbar={toolbar} loading={loading} error={error}>
            <PanelSection title="Активные отчёты" count={items.length} empty="Пока нет запланированных отчётов.">
                {items.map((report) => (
                    <ReportCard
                        key={report.id}
                        report={report}
                        busyId={busyId}
                        onPause={(item) => runAction(item, 'pause', 'Не удалось приостановить.')}
                        onResume={(item) => runAction(item, 'resume', 'Не удалось возобновить.')}
                        onCancel={(item) => runAction(item, 'cancel', 'Не удалось отменить.')}
                    />
                ))}
            </PanelSection>

            {recent.length > 0 ? (
                <PanelSection title="Недавние" count={recent.length}>
                    {recent.map((report) => (
                        <ReportCard
                            key={report.id}
                            report={report}
                            busyId={busyId}
                            onPause={() => {}}
                            onResume={() => {}}
                            onCancel={() => {}}
                        />
                    ))}
                </PanelSection>
            ) : null}
        </PanelShell>
    );
}
