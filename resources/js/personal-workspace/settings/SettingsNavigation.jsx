import { useTranslation } from '@/locales/useTranslation';

export default function SettingsNavigation({ sections, current, onSelect }) {
    const { t } = useTranslation();

    return (
        <nav aria-label={t('settings.sectionsAria')} className="space-y-1">
            {sections.map((item) => {
                const active = item.id === current;

                return (
                    <button
                        key={item.id}
                        type="button"
                        onClick={() => onSelect(item.id)}
                        className={`flex w-full flex-col rounded-xl px-3 py-2.5 text-left ${
                            active
                                ? 'bg-sky-500/15 text-white ring-1 ring-sky-400/30'
                                : 'text-slate-300 hover:bg-white/5 hover:text-white'
                        }`}
                        aria-current={active ? 'page' : undefined}
                    >
                        <span className="text-sm font-medium">{item.label}</span>
                        <span className="mt-0.5 text-[11px] leading-4 text-slate-500">{item.description}</span>
                    </button>
                );
            })}
        </nav>
    );
}
