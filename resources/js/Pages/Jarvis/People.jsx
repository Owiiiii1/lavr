import LavrAppShell from '@/telegram/LavrAppShell';
import { Head } from '@inertiajs/react';

export default function People({ title = 'People', phase = '4', body }) {
    return (
        <LavrAppShell>
            <Head title={title} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <p className="text-[11px] uppercase tracking-[0.18em] text-slate-500">Phase {phase}</p>
                <h1 className="mt-2 text-2xl font-semibold text-white">{title}</h1>
                <p className="mt-3 max-w-lg text-sm leading-6 text-slate-300">{body}</p>
                <p className="mt-6 max-w-lg text-sm leading-6 text-slate-500">
                    Здесь появится операционный слой людей и ролей. Knowledge-entity person этим экраном не является.
                </p>
            </div>
        </LavrAppShell>
    );
}
