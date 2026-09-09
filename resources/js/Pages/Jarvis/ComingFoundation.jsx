import LavrAppShell from '@/telegram/LavrAppShell';
import { Head } from '@inertiajs/react';

export default function ComingFoundation({ title, phase, body }) {
    return (
        <LavrAppShell>
            <Head title={title} />
            <div className="jarvis-workspace min-h-[100dvh] px-4 pb-6 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">Phase {phase}</p>
                <h1 className="mt-2 text-2xl font-semibold text-white">{title}</h1>
                <p className="mt-3 max-w-lg text-sm leading-6 text-slate-300">{body}</p>
            </div>
        </LavrAppShell>
    );
}
