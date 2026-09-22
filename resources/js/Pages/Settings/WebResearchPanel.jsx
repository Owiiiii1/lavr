import { router, usePage } from '@inertiajs/react';
import { translateKnown } from './integrationCopy';
import { badgeClass, normalizeState } from './settingsStatus';
import { useState } from 'react';

function statusClass(status) {
    return badgeClass(normalizeState(status));
}

export default function WebResearchPanel() {
    const { webResearch = {}, locale = 'en', errors = {} } = usePage().props;
    const [busy, setBusy] = useState(null);
    const [tavilyKey, setTavilyKey] = useState('');
    const [form, setForm] = useState({
        enabled: Boolean(webResearch.enabled),
        provider: webResearch.provider ?? 'gemini_google',
        fetch_web_page_enabled: Boolean(webResearch.fetch_web_page_enabled),
        max_search_results: webResearch.max_search_results ?? 8,
        max_searches_per_turn: webResearch.max_searches_per_turn ?? 2,
        max_fetches_per_turn: webResearch.max_fetches_per_turn ?? 4,
        max_page_chars: webResearch.max_page_chars ?? 8000,
        max_total_web_chars: webResearch.max_total_web_chars ?? 18000,
        timeout_seconds: webResearch.timeout_seconds ?? 12,
        default_recency_days: webResearch.default_recency_days ?? '',
    });

    const text = {
        en: {
            title: 'Web Research',
            hint: 'Technical search settings for Owner Conversation tools. Not a Workspace preference. SSRF and private-network rules cannot be changed here. There is no Test Connection in this milestone.',
            status: 'Status',
            enabled: 'Enable Web Research',
            provider: 'Provider',
            gemini: 'Gemini Google Search',
            tavily: 'Tavily',
            disabled: 'Disabled',
            fetch: 'Fetch web pages',
            results: 'Search results per search',
            searches: 'Max searches per turn',
            fetches: 'Max page fetches per turn',
            pageChars: 'Max page chars',
            totalChars: 'Max total web chars',
            timeout: 'Timeout (seconds)',
            recency: 'Default recency (days, optional)',
            save: 'Save settings',
            geminiSection: 'Gemini Google Search',
            geminiUses: 'Uses the existing Gemini API key from AI provider settings. No second secret is stored here.',
            geminiConfigured: 'Gemini configured',
            googleAvailable: 'Google Search provider available',
            yes: 'yes',
            no: 'no',
            tavilySection: 'Tavily',
            tavilyKey: 'API key (set or replace)',
            tavilySave: 'Save Tavily key',
            tavilyClear: 'Remove stored key',
            tavilyConfigured: 'Tavily configured',
            tavilyEnv: 'Using env WEB_SEARCH_API_KEY fallback. Saving a key here takes precedence.',
            tavilyAdmin: 'Configured from Admin.',
            fetchEnabled: 'fetch_web_page',
            activeProvider: 'Active provider',
            limits: 'Effective limits',
        },
        ru: {
            title: 'Веб-поиск',
            hint: 'Технические настройки поиска для разговора владельца. Правила доступа к внутренним сетям здесь не отключаются. Проверки подключения на этом экране нет.',
            status: 'Статус',
            enabled: 'Включить веб-поиск',
            provider: 'Провайдер',
            gemini: 'Gemini Google Search',
            tavily: 'Tavily',
            disabled: 'Выключено',
            fetch: 'Загружать страницы',
            results: 'Результатов на один поиск',
            searches: 'Поисков за один ход',
            fetches: 'Загрузок страниц за один ход',
            pageChars: 'Символов на страницу',
            totalChars: 'Символов из веба всего',
            timeout: 'Таймаут (секунды)',
            recency: 'Свежесть по умолчанию (дни, необязательно)',
            save: 'Сохранить',
            geminiSection: 'Gemini Google Search',
            geminiUses: 'Используется ключ Gemini из настроек AI. Второй секрет здесь не хранится.',
            geminiConfigured: 'Gemini настроен',
            googleAvailable: 'Провайдер Google Search доступен',
            yes: 'да',
            no: 'нет',
            tavilySection: 'Tavily',
            tavilyKey: 'API-ключ (задать или заменить)',
            tavilySave: 'Сохранить ключ Tavily',
            tavilyClear: 'Удалить сохранённый ключ',
            tavilyConfigured: 'Tavily настроен',
            tavilyEnv: 'Используется WEB_SEARCH_API_KEY из окружения. Ключ, сохранённый здесь, имеет приоритет.',
            tavilyAdmin: 'Задано в админке.',
            fetchEnabled: 'Загрузка страниц',
            activeProvider: 'Активный провайдер',
            limits: 'Действующие лимиты',
        },
        uk: {
            title: 'Веб-пошук',
            hint: 'Технічні налаштування пошуку для розмови власника. Правила доступу до внутрішніх мереж тут не вимикаються. Перевірки підключення на цьому екрані немає.',
            status: 'Статус',
            enabled: 'Увімкнути веб-пошук',
            provider: 'Провайдер',
            gemini: 'Gemini Google Search',
            tavily: 'Tavily',
            disabled: 'Вимкнено',
            fetch: 'Завантажувати сторінки',
            results: 'Результатів на один пошук',
            searches: 'Пошуків за один хід',
            fetches: 'Завантажень сторінок за один хід',
            pageChars: 'Символів на сторінку',
            totalChars: 'Символів з вебу загалом',
            timeout: 'Таймаут (секунди)',
            recency: 'Свіжість за замовчуванням (дні, необов’язково)',
            save: 'Зберегти',
            geminiSection: 'Gemini Google Search',
            geminiUses: 'Використовується ключ Gemini з налаштувань AI. Другий секрет тут не зберігається.',
            geminiConfigured: 'Gemini налаштовано',
            googleAvailable: 'Провайдер Google Search доступний',
            yes: 'так',
            no: 'ні',
            tavilySection: 'Tavily',
            tavilyKey: 'API-ключ (задати або замінити)',
            tavilySave: 'Зберегти ключ Tavily',
            tavilyClear: 'Видалити збережений ключ',
            tavilyConfigured: 'Tavily налаштовано',
            tavilyEnv: 'Використовується WEB_SEARCH_API_KEY з оточення. Ключ, збережений тут, має пріоритет.',
            tavilyAdmin: 'Задано в адмінці.',
            fetchEnabled: 'Завантаження сторінок',
            activeProvider: 'Активний провайдер',
            limits: 'Дійсні ліміти',
        },
    };
    const t = text[locale] ?? text.en;

    const ceilings = webResearch.ceilings ?? {};
    const floors = webResearch.floors ?? {};

    const field = (key, min, max) => ({
        value: form[key],
        min: floors[key] ?? min,
        max: ceilings[key] ?? max,
        onChange: (event) => setForm((current) => ({ ...current, [key]: event.target.value })),
    });

    const statusLabel = translateKnown(locale, webResearch.status_label) ?? t.disabled;

    const saveSettings = (event) => {
        event.preventDefault();
        setBusy('save');
        router.post(
            route('settings.web-research.update'),
            {
                ...form,
                enabled: form.enabled ? 1 : 0,
                fetch_web_page_enabled: form.fetch_web_page_enabled ? 1 : 0,
                default_recency_days: form.default_recency_days === '' ? null : form.default_recency_days,
            },
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    const saveTavily = (event) => {
        event.preventDefault();
        setBusy('tavily');
        router.post(
            route('settings.web-research.tavily-key'),
            { tavily_api_key: tavilyKey },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(null);
                    setTavilyKey('');
                },
            },
        );
    };

    const clearTavily = () => {
        setBusy('clear');
        router.post(
            route('settings.web-research.tavily-key.clear'),
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    const inputClass =
        'mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800';
    const labelClass = 'block text-sm font-medium text-slate-700';

    return (
        <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-slate-900">{t.title}</h2>
                    <p className="mt-1 max-w-3xl text-sm text-slate-600">{t.hint}</p>
                </div>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(webResearch.status)}`}>
                    {statusLabel}
                </span>
            </div>

            <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-lg bg-white/70 px-3 py-2">
                    <dt className="text-xs uppercase tracking-wide text-slate-500">{t.status}</dt>
                    <dd className="mt-1 text-sm font-medium text-slate-800">
                        {webResearch.runtime_enabled ? 'Enabled' : 'Disabled'}
                    </dd>
                </div>
                <div className="rounded-lg bg-white/70 px-3 py-2">
                    <dt className="text-xs uppercase tracking-wide text-slate-500">{t.activeProvider}</dt>
                    <dd className="mt-1 text-sm font-medium text-slate-800">
                        {webResearch.active_provider_label ?? 'Disabled'}
                    </dd>
                </div>
                <div className="rounded-lg bg-white/70 px-3 py-2">
                    <dt className="text-xs uppercase tracking-wide text-slate-500">{t.fetchEnabled}</dt>
                    <dd className="mt-1 text-sm font-medium text-slate-800">
                        {webResearch.fetch_effective ? t.yes : t.no}
                    </dd>
                </div>
                <div className="rounded-lg bg-white/70 px-3 py-2">
                    <dt className="text-xs uppercase tracking-wide text-slate-500">{t.limits}</dt>
                    <dd className="mt-1 text-sm font-medium text-slate-800">
                        {webResearch.max_search_results}/{webResearch.max_searches_per_turn}/
                        {webResearch.max_fetches_per_turn} · {webResearch.max_page_chars}/
                        {webResearch.max_total_web_chars} · {webResearch.timeout_seconds}s
                    </dd>
                </div>
            </dl>

            <form onSubmit={saveSettings} className="mt-5 space-y-4">
                <div className="flex flex-wrap gap-6">
                    <label className="inline-flex items-center gap-2 text-sm text-slate-800">
                        <input
                            type="checkbox"
                            checked={Boolean(form.enabled)}
                            onChange={(event) => setForm((current) => ({ ...current, enabled: event.target.checked }))}
                        />
                        {t.enabled}
                    </label>
                    <label className="inline-flex items-center gap-2 text-sm text-slate-800">
                        <input
                            type="checkbox"
                            checked={Boolean(form.fetch_web_page_enabled)}
                            onChange={(event) =>
                                setForm((current) => ({ ...current, fetch_web_page_enabled: event.target.checked }))
                            }
                        />
                        {t.fetch}
                    </label>
                </div>

                <label className={labelClass}>
                    {t.provider}
                    <select
                        className={inputClass}
                        value={form.provider}
                        onChange={(event) => setForm((current) => ({ ...current, provider: event.target.value }))}
                    >
                        <option value="gemini_google">{t.gemini}</option>
                        <option value="tavily">{t.tavily}</option>
                        <option value="disabled">{t.disabled}</option>
                    </select>
                </label>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label className={labelClass}>
                        {t.results}
                        <input type="number" className={inputClass} {...field('max_search_results', 1, 20)} />
                    </label>
                    <label className={labelClass}>
                        {t.searches}
                        <input type="number" className={inputClass} {...field('max_searches_per_turn', 1, 10)} />
                    </label>
                    <label className={labelClass}>
                        {t.fetches}
                        <input type="number" className={inputClass} {...field('max_fetches_per_turn', 0, 10)} />
                    </label>
                    <label className={labelClass}>
                        {t.pageChars}
                        <input type="number" className={inputClass} {...field('max_page_chars', 500, 20000)} />
                    </label>
                    <label className={labelClass}>
                        {t.totalChars}
                        <input type="number" className={inputClass} {...field('max_total_web_chars', 1000, 40000)} />
                    </label>
                    <label className={labelClass}>
                        {t.timeout}
                        <input type="number" className={inputClass} {...field('timeout_seconds', 2, 60)} />
                    </label>
                    <label className={labelClass}>
                        {t.recency}
                        <input
                            type="number"
                            min={1}
                            max={365}
                            className={inputClass}
                            value={form.default_recency_days}
                            onChange={(event) =>
                                setForm((current) => ({ ...current, default_recency_days: event.target.value }))
                            }
                        />
                    </label>
                </div>

                {errors.enabled || errors.provider || errors.max_search_results ? (
                    <p className="text-sm text-red-700">
                        {errors.enabled || errors.provider || errors.max_search_results}
                    </p>
                ) : null}

                <button
                    type="submit"
                    disabled={busy !== null}
                    className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                >
                    {t.save}
                </button>
            </form>

            {form.provider === 'gemini_google' || webResearch.provider === 'gemini_google' ? (
                <div className="mt-6 rounded-lg border border-slate-200 bg-white p-4">
                    <h3 className="text-sm font-semibold text-slate-900">{t.geminiSection}</h3>
                    <p className="mt-1 text-sm text-slate-600">{t.geminiUses}</p>
                    <ul className="mt-3 space-y-1 text-sm text-slate-700">
                        <li>
                            {t.geminiConfigured}: {webResearch.gemini_configured ? t.yes : t.no}
                        </li>
                        <li>
                            {t.googleAvailable}: {webResearch.google_search_available ? t.yes : t.no}
                        </li>
                    </ul>
                </div>
            ) : null}

            {form.provider === 'tavily' || webResearch.provider === 'tavily' ? (
                <div className="mt-6 rounded-lg border border-slate-200 bg-white p-4">
                    <h3 className="text-sm font-semibold text-slate-900">{t.tavilySection}</h3>
                    <p className="mt-2 text-sm text-slate-700">
                        {t.tavilyConfigured}: {webResearch.tavily_configured ? t.yes : t.no}
                    </p>
                    {webResearch.tavily_key_source === 'env' ? (
                        <p className="mt-1 text-sm text-slate-600">{t.tavilyEnv}</p>
                    ) : null}
                    {webResearch.tavily_key_source === 'admin' ? (
                        <p className="mt-1 text-sm text-slate-600">{t.tavilyAdmin}</p>
                    ) : null}
                    <form onSubmit={saveTavily} className="mt-3 flex flex-wrap items-end gap-2">
                        <label className={`${labelClass} min-w-[16rem] flex-1`}>
                            {t.tavilyKey}
                            <input
                                type="password"
                                autoComplete="off"
                                className={inputClass}
                                value={tavilyKey}
                                onChange={(event) => setTavilyKey(event.target.value)}
                                placeholder="••••••••"
                            />
                        </label>
                        <button
                            type="submit"
                            disabled={busy !== null || tavilyKey.trim() === ''}
                            className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                        >
                            {t.tavilySave}
                        </button>
                        {webResearch.tavily_key_source === 'admin' ? (
                            <button
                                type="button"
                                onClick={clearTavily}
                                disabled={busy !== null}
                                className="inline-flex h-9 items-center rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                            >
                                {t.tavilyClear}
                            </button>
                        ) : null}
                    </form>
                    {errors.tavily_api_key ? <p className="mt-2 text-sm text-red-700">{errors.tavily_api_key}</p> : null}
                </div>
            ) : null}
        </section>
    );
}
