import SettingsCard from '@/personal-workspace/settings/SettingsCard';
import { workspaceRoute } from '@/personal-workspace/named';
import { useTranslation } from '@/locales/useTranslation';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const SWITCH = [
    { code: 'uk', short: 'UA' },
    { code: 'en', short: 'EN' },
    { code: 'ru', short: 'RU' },
];

export default function LanguageSettings({ surface }) {
    const { t, locale, assistantLocale } = useTranslation();
    const [pending, setPending] = useState(false);

    const save = (next) => {
        setPending(true);
        router.patch(workspaceRoute(surface, 'settings.locales.update'), next, {
            preserveScroll: true,
            onFinish: () => setPending(false),
        });
    };

    return (
        <SettingsCard title={t('settings.language')} description={t('settings.languageHint')}>
            <div className="space-y-4">
                <div>
                    <p className="text-xs uppercase tracking-[0.14em] text-slate-500">{t('settings.uiLanguage')}</p>
                    <div className="mt-2 inline-flex rounded-xl border border-white/10 bg-black/30 p-1" role="group" aria-label={t('settings.uiLanguage')}>
                        {SWITCH.map((item) => {
                            const active = locale === item.code;

                            return (
                                <button
                                    key={item.code}
                                    type="button"
                                    disabled={pending}
                                    onClick={() => save({
                                        interface_locale: item.code,
                                        assistant_locale: assistantLocale,
                                    })}
                                    className={`min-h-10 min-w-12 rounded-lg px-3 text-sm font-semibold disabled:opacity-60 ${
                                        active ? 'bg-sky-500 text-white' : 'text-slate-300 hover:text-white'
                                    }`}
                                    aria-pressed={active}
                                >
                                    {item.short}
                                </button>
                            );
                        })}
                    </div>
                </div>
                <div>
                    <label className="text-xs uppercase tracking-[0.14em] text-slate-500" htmlFor="assistant-locale">
                        {t('settings.assistantLanguage')}
                    </label>
                    <select
                        id="assistant-locale"
                        value={assistantLocale}
                        disabled={pending}
                        onChange={(event) => save({
                            interface_locale: locale,
                            assistant_locale: event.target.value,
                        })}
                        className="mt-2 w-full rounded-lg border border-white/10 bg-black/30 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-400/40 disabled:opacity-60"
                    >
                        <option value="uk">{t('settings.uk')}</option>
                        <option value="en">{t('settings.en')}</option>
                        <option value="ru">{t('settings.ru')}</option>
                    </select>
                </div>
            </div>
        </SettingsCard>
    );
}
