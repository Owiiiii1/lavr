import SettingsStatusCard from './SettingsStatusCard';
import { CONNECTION_ELEMENTS, READY } from './settingsStatus';

export default function OverviewPanel({ statuses, t, onOpen }) {
    const groups = [
        { title: t.groupAi, items: [{ id: 'ai', title: t.tabAi, hint: t.hintAi }] },
        { title: t.groupChannels, items: [{ id: 'telegram', title: t.tabTelegram, hint: t.hintTelegram }] },
        {
            title: t.groupSources,
            items: [
                { id: 'google', title: t.tabGoogle, hint: t.hintGoogle },
                { id: 'zoom', title: t.tabZoom, hint: t.hintZoom },
            ],
        },
        {
            title: t.groupAbilities,
            items: [
                { id: 'voice', title: t.tabVoice, hint: t.hintVoice },
                { id: 'web-research', title: t.tabWebResearch, hint: t.hintWebResearch },
            ],
        },
        { title: t.groupDiagnostics, items: [{ id: 'activity', title: t.tabActivity, hint: t.hintActivity }] },
    ];

    const working = CONNECTION_ELEMENTS.filter((id) => statuses[id]?.state === READY).length;

    return (
        <div className="space-y-6">
            <div className="rounded-xl border border-[#E6DCC8] bg-white p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-base font-semibold text-slate-900">{t.tabOverview}</h2>
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                        {working} / {CONNECTION_ELEMENTS.length} {t.summaryWorking}
                    </span>
                </div>
                <p className="mt-2 text-sm text-slate-600">{t.overviewIntro}</p>
            </div>

            {groups.map((group) => (
                <section key={group.title} className="space-y-3">
                    <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{group.title}</h3>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {group.items.map((item) => (
                            <SettingsStatusCard
                                key={item.id}
                                title={item.title}
                                hint={item.hint}
                                status={statuses[item.id]}
                                t={t}
                                onOpen={() => onOpen(item.id)}
                            />
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
