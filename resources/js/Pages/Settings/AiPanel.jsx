import { router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Loader2, PlugZap, Save } from 'lucide-react';
import { useMemo, useState } from 'react';

const PROVIDERS = [
    { provider: 'openai', title: 'ChatGPT / OpenAI' },
    { provider: 'anthropic', title: 'Claude / Anthropic' },
    { provider: 'gemini', title: 'Gemini / Google' },
];

export default function AiPanel() {
    const { providers = [], aiRoles = [], locale = 'en', errors = {} } = usePage().props;
    const [processingProvider, setProcessingProvider] = useState(null);
    const [apiKeys, setApiKeys] = useState({});

    const text = {
        en: {
            credentialsTitle: 'Provider credentials',
            credentialsSubtitle:
                'API keys and model discovery only. Conversation runtime uses the three AI configurations below, not the legacy “one active model” flag.',
            rolesTitle: 'AI configurations',
            rolesSubtitle:
                'Owner Conversation, Owner Analysis, and Default User Conversation are independent. Analysis is not used in Telegram DMs.',
            apiKey: 'API key',
            saveKey: 'Save key',
            check: 'Check connection',
            modelCatalog: 'Discovered models',
            noModels: 'No models loaded yet',
            notConnected: 'Not connected',
            connected: 'Connected',
            error: 'Error',
            savedMask: 'Saved key',
            enabled: 'Enabled',
            provider: 'Provider',
            model: 'Model',
            systemPrompt: 'System prompt',
            temperature: 'Temperature',
            maxTokens: 'Max tokens',
            recentLimit: 'Recent messages',
            saveRole: 'Save configuration',
            selectProvider: 'Select provider',
            selectModel: 'Select model',
            disabledNotice: 'A model is selected, but this AI configuration is disabled. Turn on “Enabled” and save it.',
        },
        ru: {
            credentialsTitle: 'Ключи провайдеров',
            credentialsSubtitle:
                'Здесь хранятся API-ключи и загружается список моделей. Рабочие модели включаются отдельно в конфигурациях ниже.',
            rolesTitle: 'Конфигурации AI',
            rolesSubtitle:
                'Owner Conversation, Owner Analysis и Default User Conversation независимы. Выбор модели сам по себе не включает конфигурацию.',
            apiKey: 'API-ключ',
            saveKey: 'Сохранить ключ',
            check: 'Проверить подключение',
            modelCatalog: 'Доступные модели',
            noModels: 'Модели ещё не загружены',
            notConnected: 'Не подключено',
            connected: 'Подключено',
            error: 'Ошибка',
            savedMask: 'Сохранённый ключ',
            enabled: 'Включено',
            provider: 'Провайдер',
            model: 'Модель',
            systemPrompt: 'Системный промпт',
            temperature: 'Температура',
            maxTokens: 'Максимум токенов',
            recentLimit: 'Последние сообщения',
            saveRole: 'Сохранить конфигурацию',
            selectProvider: 'Выберите провайдера',
            selectModel: 'Выберите модель',
            disabledNotice: 'Модель выбрана, но эта конфигурация AI выключена. Включите переключатель «Включено» и сохраните.',
        },
        uk: {
            credentialsTitle: 'Ключі провайдерів',
            credentialsSubtitle:
                'Тут зберігаються API-ключі та завантажується список моделей. Робочі моделі вмикаються окремо в конфігураціях нижче.',
            rolesTitle: 'Конфігурації AI',
            rolesSubtitle:
                'Owner Conversation, Owner Analysis і Default User Conversation незалежні. Вибір моделі сам по собі не вмикає конфігурацію.',
            apiKey: 'API-ключ',
            saveKey: 'Зберегти ключ',
            check: 'Перевірити підключення',
            modelCatalog: 'Доступні моделі',
            noModels: 'Моделі ще не завантажені',
            notConnected: 'Не підключено',
            connected: 'Підключено',
            error: 'Помилка',
            savedMask: 'Збережений ключ',
            enabled: 'Увімкнено',
            provider: 'Провайдер',
            model: 'Модель',
            systemPrompt: 'Системний промпт',
            temperature: 'Температура',
            maxTokens: 'Максимум токенів',
            recentLimit: 'Останні повідомлення',
            saveRole: 'Зберегти конфігурацію',
            selectProvider: 'Оберіть провайдера',
            selectModel: 'Оберіть модель',
            disabledNotice: 'Модель обрана, але ця конфігурація AI вимкнена. Увімкніть перемикач «Увімкнено» та збережіть.',
        },
    };
    const t = text[locale] ?? text.en;

    const providerMap = useMemo(
        () => Object.fromEntries(providers.map((item) => [item.provider, item])),
        [providers],
    );

    const connectedProviders = providers.filter((item) => item.is_connected && item.supports_chat !== false);

    const statusChip = (item) => {
        if (item?.is_connected) {
            return { label: t.connected, className: 'bg-indigo-100 text-indigo-700' };
        }
        if (item?.last_error) {
            return { label: t.error, className: 'bg-red-100 text-red-700' };
        }

        return { label: t.notConnected, className: 'bg-slate-100 text-slate-700' };
    };

    const submitWithLock = (provider, callback) => {
        setProcessingProvider(provider);
        callback({
            preserveScroll: true,
            onFinish: () => setProcessingProvider(null),
        });
    };

    return (
        <div className="space-y-8">
            <section className="space-y-4">
                <div className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">{t.credentialsTitle}</h2>
                    <p className="mt-1 text-sm text-slate-600">{t.credentialsSubtitle}</p>
                </div>

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    {PROVIDERS.map(({ provider, title }) => {
                        const item = providerMap[provider] ?? {};
                        const chip = statusChip(item);
                        const models = item.available_models ?? [];
                        const busy = processingProvider === provider;

                        return (
                            <div key={provider} className="app-widget p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                                    <span className={`rounded-full px-2 py-1 text-xs font-semibold ${chip.className}`}>
                                        {chip.label}
                                    </span>
                                </div>

                                <div className="mt-4 space-y-3">
                                    <label className="block text-sm font-medium text-slate-700">{t.apiKey}</label>
                                    <input
                                        type="password"
                                        placeholder={item.has_api_key ? '••••••••' : ''}
                                        value={apiKeys[provider] ?? ''}
                                        onChange={(e) =>
                                            setApiKeys((prev) => ({ ...prev, [provider]: e.target.value }))
                                        }
                                        className="block h-10 w-full rounded-lg border border-slate-300 px-3 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                                    />
                                    {item.api_key_masked && (
                                        <p className="text-xs text-slate-500">
                                            {t.savedMask}: <span className="font-medium">{item.api_key_masked}</span>
                                        </p>
                                    )}

                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            disabled={busy || !(apiKeys[provider] ?? '').trim()}
                                            onClick={() =>
                                                submitWithLock(provider, (opts) =>
                                                    router.post(
                                                        route('ai-settings.save-key', provider),
                                                        {
                                                            provider,
                                                            api_key: apiKeys[provider],
                                                        },
                                                        {
                                                            ...opts,
                                                            onSuccess: () =>
                                                                setApiKeys((prev) => ({
                                                                    ...prev,
                                                                    [provider]: '',
                                                                })),
                                                        },
                                                    ),
                                                )
                                            }
                                            className="inline-flex h-9 items-center gap-2 rounded-lg bg-indigo-600 px-3 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-60"
                                        >
                                            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
                                            <span className="whitespace-nowrap">{t.saveKey}</span>
                                        </button>
                                        <button
                                            type="button"
                                            disabled={busy || !item.has_api_key}
                                            onClick={() =>
                                                submitWithLock(provider, (opts) =>
                                                    router.post(
                                                        route('ai-settings.check', provider),
                                                        { provider },
                                                        opts,
                                                    ),
                                                )
                                            }
                                            className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                        >
                                            <PlugZap className="h-4 w-4" />
                                            <span className="whitespace-nowrap">{t.check}</span>
                                        </button>
                                    </div>

                                    <div className="space-y-2 pt-1">
                                        <label className="block text-sm font-medium text-slate-700">{t.modelCatalog}</label>
                                        <select
                                            defaultValue=""
                                            className="block h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm shadow-sm"
                                            disabled={models.length === 0}
                                        >
                                            <option value="">{models.length === 0 ? t.noModels : `${models.length} models`}</option>
                                            {models.map((model) => (
                                                <option key={model.id} value={model.id}>
                                                    {model.name ?? model.id}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {item.last_error && (
                                        <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                                            {item.last_error}
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </section>

            <section className="space-y-4">
                <div className="app-widget p-4">
                    <h2 className="text-base font-semibold text-slate-900">{t.rolesTitle}</h2>
                    <p className="mt-1 text-sm text-slate-600">{t.rolesSubtitle}</p>
                </div>

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
                    {aiRoles.map((role) => (
                        <RoleConfigCard
                            key={role.role_key}
                            role={role}
                            connectedProviders={connectedProviders}
                            providerMap={providerMap}
                            t={t}
                        />
                    ))}
                </div>
            </section>

            {errors.ai ? <p className="mt-4 text-sm text-red-600">{errors.ai}</p> : null}
        </div>
    );
}

function RoleConfigCard({ role, connectedProviders, providerMap, t }) {
    const form = useForm({
        provider: role.provider ?? '',
        model: role.model ?? '',
        system_prompt: role.system_prompt ?? '',
        is_enabled: Boolean(role.is_enabled),
        parameters: {
            temperature: role.parameters?.temperature ?? '',
            max_tokens: role.parameters?.max_tokens ?? '',
            recent_message_limit: role.parameters?.recent_message_limit ?? 30,
        },
    });

    const selectedProvider = providerMap[form.data.provider] ?? {};
    const models = selectedProvider.available_models ?? [];
    const canEnable =
        Boolean(form.data.provider) &&
        Boolean(form.data.model) &&
        Boolean(selectedProvider.is_connected) &&
        selectedProvider.supports_chat !== false;

    return (
        <form
            className="app-widget space-y-3 p-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.patch(route('ai-settings.roles.update', role.role_key), { preserveScroll: true });
            }}
        >
            <div className="flex items-start justify-between gap-2">
                <h3 className="text-base font-semibold text-slate-900">{role.label}</h3>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.data.is_enabled}
                        disabled={!canEnable && !form.data.is_enabled}
                        onChange={(e) => form.setData('is_enabled', e.target.checked)}
                    />
                    {t.enabled}
                </label>
            </div>

            {!form.data.is_enabled && form.data.provider && form.data.model ? (
                <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    {t.disabledNotice}
                </p>
            ) : null}

            <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">{t.provider}</label>
                <select
                    value={form.data.provider}
                    onChange={(e) => {
                        form.setData({
                            ...form.data,
                            provider: e.target.value,
                            model: '',
                            is_enabled: false,
                        });
                    }}
                    className="block h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm shadow-sm"
                >
                    <option value="">{t.selectProvider}</option>
                    {connectedProviders.map((item) => (
                        <option key={item.provider} value={item.provider}>
                            {item.label}
                        </option>
                    ))}
                </select>
            </div>

            <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">{t.model}</label>
                <select
                    value={form.data.model}
                    onChange={(e) => form.setData('model', e.target.value)}
                    className="block h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm shadow-sm"
                    disabled={models.length === 0}
                >
                    <option value="">{t.selectModel}</option>
                    {models.map((model) => (
                        <option key={model.id} value={model.id}>
                            {model.name ?? model.id}
                        </option>
                    ))}
                </select>
            </div>

            <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">{t.systemPrompt}</label>
                <textarea
                    rows={8}
                    value={form.data.system_prompt}
                    onChange={(e) => form.setData('system_prompt', e.target.value)}
                    className="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                />
            </div>

            <div className="grid grid-cols-3 gap-2">
                <NumberField
                    label={t.temperature}
                    value={form.data.parameters.temperature}
                    onChange={(value) =>
                        form.setData('parameters', { ...form.data.parameters, temperature: value })
                    }
                />
                <NumberField
                    label={t.maxTokens}
                    value={form.data.parameters.max_tokens}
                    onChange={(value) =>
                        form.setData('parameters', { ...form.data.parameters, max_tokens: value })
                    }
                />
                <NumberField
                    label={t.recentLimit}
                    value={form.data.parameters.recent_message_limit}
                    onChange={(value) =>
                        form.setData('parameters', { ...form.data.parameters, recent_message_limit: value })
                    }
                />
            </div>

            <button
                type="submit"
                disabled={form.processing}
                aria-label={t.saveRole}
                title={t.saveRole}
                className="inline-flex h-10 min-w-48 items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 disabled:opacity-60"
            >
                {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                <span className="whitespace-nowrap">{t.saveRole}</span>
            </button>
        </form>
    );
}

function NumberField({ label, value, onChange }) {
    return (
        <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">{label}</label>
            <input
                type="number"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="block h-10 w-full rounded-lg border border-slate-300 px-2 text-sm shadow-sm"
            />
        </div>
    );
}
