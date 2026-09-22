import { router, usePage } from '@inertiajs/react';
import { translateKnown } from './integrationCopy';
import { badgeClass, normalizeState } from './settingsStatus';
import { useState } from 'react';

function statusClass(status) {
    return badgeClass(normalizeState(status));
}

export default function VoicePanel() {
    const { voice = {}, locale = 'en', errors = {} } = usePage().props;
    const [busy, setBusy] = useState(null);
    const [elevenKey, setElevenKey] = useState('');
    const [form, setForm] = useState({
        stt_provider: voice.stt_provider ?? 'none',
        tts_provider: voice.tts_provider ?? 'none',
        spoken_style_enabled: Boolean(voice.spoken_style_enabled),
        stt_model: voice.stt_model ?? voice.stt_model_default ?? 'gemini-3.5-transcribe',
        telegram_tts_speed: Number(voice.telegram_tts_speed ?? voice.telegram_tts_speed_default ?? 1.15),
    });

    const text = {
        en: {
            title: 'Voice / Speech',
            hint: 'Technical STT/TTS settings. This does not change Conversation AI. No Test Connection. Telephony is out of scope.',
            status: 'Status',
            stt: 'STT provider',
            tts: 'TTS provider',
            sttModel: 'STT model',
            none: 'None',
            gemini: 'Gemini',
            openai: 'OpenAI',
            elevenlabs: 'ElevenLabs',
            geminiSection: 'Gemini STT',
            geminiHelp: 'Uses the existing Gemini credential from AI Settings. No second API key. Transcription is not Conversation AI.',
            geminiConfigured: 'Gemini configured',
            geminiCredential: 'Credential reused from AI Settings',
            geminiNoKey: 'No Voice/Speech Gemini API key field. Configure Gemini under AI provider credentials.',
            spoken: 'Spoken-style presentation hint',
            spokenHelp: 'Adds a bounded spoken-response hint. It is not a second personality prompt.',
            telegramTtsSpeed: 'Telegram TTS speed',
            telegramTtsSpeedHelp: 'Скорость голосовых ответов LAVR в Telegram. 1.00 — обычная скорость ElevenLabs.',
            save: 'Save settings',
            sttConfigured: 'STT configured',
            ttsConfigured: 'TTS configured',
            openaiConfigured: 'OpenAI configured',
            elevenConfigured: 'ElevenLabs configured',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Encrypted key for TTS only. Conversation AI stays on the current AI provider settings.',
            elevenKey: 'API key (set or replace)',
            elevenSave: 'Save ElevenLabs key',
            elevenClear: 'Remove stored key',
            elevenEnv: 'Using env ELEVENLABS_API_KEY fallback. Saving a key here takes precedence.',
            elevenAdmin: 'Configured from Admin.',
            openaiHelp: 'OpenAI Whisper uses the existing OpenAI key from AI provider settings. It does not go through Conversation AI chat.',
            yes: 'yes',
            no: 'no',
            limits: 'Hard bounds (config)',
            realtime: 'Realtime Conversation',
            realtimeHelp: 'Web «Диалог Beta» only. Telegram and Рация stay on the legacy path. Configure env ELEVENLABS_REALTIME_ENABLED, ELEVENLABS_AGENT_ID, and ELEVENLABS_CUSTOM_LLM_SECRET.',
            realtimeConfigured: 'Configured',
            realtimeNotConfigured: 'Not configured',
        },
        ru: {
            title: 'Голос',
            hint: 'Технические настройки распознавания и синтеза речи. Разговорный AI не меняется. Проверки подключения нет. Телефония здесь не настраивается.',
            status: 'Статус',
            stt: 'Распознавание речи',
            tts: 'Синтез речи',
            sttModel: 'Модель распознавания',
            none: 'Нет',
            gemini: 'Gemini',
            openai: 'OpenAI',
            elevenlabs: 'ElevenLabs',
            geminiSection: 'Распознавание Gemini',
            geminiHelp: 'Используется ключ Gemini из настроек AI. Второго ключа нет. Распознавание — это не разговорный AI.',
            geminiConfigured: 'Gemini настроен',
            geminiCredential: 'Ключ берётся из настроек AI',
            geminiNoKey: 'Отдельного ключа Gemini для голоса нет. Gemini настраивается в учётных данных AI.',
            spoken: 'Подсказка устного стиля',
            spokenHelp: 'Короткая подсказка для устной речи. Это не второй системный промпт.',
            telegramTtsSpeed: 'Скорость голоса в Telegram',
            telegramTtsSpeedHelp: 'Скорость голосовых ответов LAVR в Telegram. 1.00 — обычная скорость ElevenLabs.',
            save: 'Сохранить',
            sttConfigured: 'Распознавание настроено',
            ttsConfigured: 'Синтез настроен',
            openaiConfigured: 'OpenAI настроен',
            elevenConfigured: 'ElevenLabs настроен',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Зашифрованный ключ только для синтеза речи. Разговорный AI остаётся на текущих настройках AI.',
            elevenKey: 'API-ключ (задать или заменить)',
            elevenSave: 'Сохранить ключ ElevenLabs',
            elevenClear: 'Удалить сохранённый ключ',
            elevenEnv: 'Используется ELEVENLABS_API_KEY из окружения. Ключ, сохранённый здесь, имеет приоритет.',
            elevenAdmin: 'Задано в админке.',
            openaiHelp: 'OpenAI Whisper использует ключ OpenAI из настроек AI. Это не чат разговорного AI.',
            yes: 'да',
            no: 'нет',
            limits: 'Жёсткие пределы',
            realtime: 'Живой диалог',
            realtimeHelp: 'Только веб «Диалог Beta». Telegram и Рация без изменений. Переменные: ELEVENLABS_REALTIME_ENABLED, ELEVENLABS_AGENT_ID, ELEVENLABS_CUSTOM_LLM_SECRET.',
            realtimeConfigured: 'Настроено',
            realtimeNotConfigured: 'Не настроено',
        },
        uk: {
            title: 'Голос',
            hint: 'Технічні налаштування розпізнавання та синтезу мовлення. Розмовний AI не змінюється. Перевірки підключення немає. Телефонія тут не налаштовується.',
            status: 'Статус',
            stt: 'Розпізнавання мовлення',
            tts: 'Синтез мовлення',
            sttModel: 'Модель розпізнавання',
            none: 'Немає',
            gemini: 'Gemini',
            openai: 'OpenAI',
            elevenlabs: 'ElevenLabs',
            geminiSection: 'Розпізнавання Gemini',
            geminiHelp: 'Використовується ключ Gemini з налаштувань AI. Другого ключа немає. Розпізнавання — це не розмовний AI.',
            geminiConfigured: 'Gemini налаштовано',
            geminiCredential: 'Ключ береться з налаштувань AI',
            geminiNoKey: 'Окремого ключа Gemini для голосу немає. Gemini налаштовується в облікових даних AI.',
            spoken: 'Підказка усного стилю',
            spokenHelp: 'Коротка підказка для усного мовлення. Це не другий системний промпт.',
            telegramTtsSpeed: 'Швидкість голосу в Telegram',
            telegramTtsSpeedHelp: 'Швидкість голосових відповідей LAVR у Telegram. 1.00 — звичайна швидкість ElevenLabs.',
            save: 'Зберегти',
            sttConfigured: 'Розпізнавання налаштовано',
            ttsConfigured: 'Синтез налаштовано',
            openaiConfigured: 'OpenAI налаштовано',
            elevenConfigured: 'ElevenLabs налаштовано',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Зашифрований ключ лише для синтезу мовлення. Розмовний AI лишається на поточних налаштуваннях AI.',
            elevenKey: 'API-ключ (задати або замінити)',
            elevenSave: 'Зберегти ключ ElevenLabs',
            elevenClear: 'Видалити збережений ключ',
            elevenEnv: 'Використовується ELEVENLABS_API_KEY з оточення. Ключ, збережений тут, має пріоритет.',
            elevenAdmin: 'Задано в адмінці.',
            openaiHelp: 'OpenAI Whisper використовує ключ OpenAI з налаштувань AI. Це не чат розмовного AI.',
            yes: 'так',
            no: 'ні',
            limits: 'Жорсткі межі',
            realtime: 'Живий діалог',
            realtimeHelp: 'Лише веб «Діалог Beta». Telegram і Рація без змін. Змінні: ELEVENLABS_REALTIME_ENABLED, ELEVENLABS_AGENT_ID, ELEVENLABS_CUSTOM_LLM_SECRET.',
            realtimeConfigured: 'Налаштовано',
            realtimeNotConfigured: 'Не налаштовано',
        },
    };
    const t = text[locale] ?? text.en;
    const limits = voice.limits ?? {};

    const submit = (event) => {
        event.preventDefault();
        setBusy('save');
        router.post(route('settings.voice.update'), form, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const saveKey = (event) => {
        event.preventDefault();
        setBusy('key');
        router.post(route('settings.voice.elevenlabs-key'), { elevenlabs_api_key: elevenKey }, {
            preserveScroll: true,
            onSuccess: () => setElevenKey(''),
            onFinish: () => setBusy(null),
        });
    };

    const clearKey = () => {
        setBusy('clear');
        router.post(route('settings.voice.elevenlabs-key.clear'), {}, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    return (
        <section className="space-y-6 rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-slate-900">{t.title}</h2>
                    <p className="mt-1 text-sm text-slate-600">{t.hint}</p>
                </div>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(voice.status)}`}>
                    {translateKnown(locale, voice.status_label) ?? t.none}
                </span>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <label className="block text-sm text-slate-700">
                    {t.stt}
                    <select
                        value={form.stt_provider}
                        onChange={(event) => {
                            const stt_provider = event.target.value;
                            setForm((current) => ({
                                ...current,
                                stt_provider,
                                stt_model: stt_provider === 'gemini'
                                    ? (current.stt_model || voice.stt_model_default || 'gemini-3.5-transcribe')
                                    : current.stt_model,
                            }));
                        }}
                        className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                    >
                        <option value="none">{t.none}</option>
                        <option value="gemini">{t.gemini}</option>
                        <option value="openai">{t.openai}</option>
                    </select>
                </label>
                {form.stt_provider === 'openai' && <p className="text-xs text-slate-500">{t.openaiHelp}</p>}

                {form.stt_provider === 'gemini' && (
                    <div className="space-y-3 rounded-lg border border-[#E6DCC8] bg-white/60 p-3">
                        <h3 className="text-sm font-semibold text-slate-900">{t.geminiSection}</h3>
                        <p className="text-sm text-slate-600">{t.geminiHelp}</p>
                        <p className="text-xs text-slate-500">{t.geminiCredential}</p>
                        <p className="text-xs text-slate-500">{t.geminiNoKey}</p>
                        <dl className="grid gap-2 text-sm text-slate-700 sm:grid-cols-2">
                            <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                                <dt>{t.geminiConfigured}</dt>
                                <dd>{voice.gemini_configured ? t.yes : t.no}</dd>
                            </div>
                        </dl>
                        <label className="block text-sm text-slate-700">
                            {t.sttModel}
                            <input
                                value={form.stt_model}
                                onChange={(event) => setForm((current) => ({ ...current, stt_model: event.target.value }))}
                                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                            />
                        </label>
                    </div>
                )}

                <label className="block text-sm text-slate-700">
                    {t.tts}
                    <select
                        value={form.tts_provider}
                        onChange={(event) => setForm((current) => ({ ...current, tts_provider: event.target.value }))}
                        className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"
                    >
                        <option value="none">{t.none}</option>
                        <option value="elevenlabs">{t.elevenlabs}</option>
                    </select>
                </label>

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.spoken_style_enabled}
                        onChange={(event) => setForm((current) => ({ ...current, spoken_style_enabled: event.target.checked }))}
                    />
                    {t.spoken}
                </label>
                <p className="text-xs text-slate-500">{t.spokenHelp}</p>

                <div className="space-y-2">
                    <label className="block text-sm text-slate-700" htmlFor="telegram-tts-speed">
                        {t.telegramTtsSpeed}
                    </label>
                    <p className="text-xs text-slate-500">{t.telegramTtsSpeedHelp}</p>
                    <div className="flex items-center gap-3">
                        <input
                            id="telegram-tts-speed"
                            type="range"
                            min={voice.telegram_tts_speed_min ?? 0.7}
                            max={voice.telegram_tts_speed_max ?? 1.2}
                            step={voice.telegram_tts_speed_step ?? 0.05}
                            value={form.telegram_tts_speed}
                            onChange={(event) => setForm((current) => ({
                                ...current,
                                telegram_tts_speed: Number(event.target.value),
                            }))}
                            className="w-full accent-slate-900"
                        />
                        <span className="w-12 shrink-0 text-right text-sm font-medium tabular-nums text-slate-900">
                            {Number(form.telegram_tts_speed).toFixed(2)}
                        </span>
                    </div>
                    <div className="flex justify-between text-xs text-slate-500">
                        <span>0.70</span>
                        <span>1.00</span>
                        <span>1.20</span>
                    </div>
                </div>

                {errors.stt_provider && <p className="text-sm text-red-600">{errors.stt_provider}</p>}
                {errors.stt_model && <p className="text-sm text-red-600">{errors.stt_model}</p>}
                {errors.telegram_tts_speed && <p className="text-sm text-red-600">{errors.telegram_tts_speed}</p>}

                <button
                    type="submit"
                    disabled={busy !== null}
                    className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                >
                    {t.save}
                </button>
            </form>

            <dl className="grid gap-2 text-sm text-slate-700 sm:grid-cols-2">
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.sttConfigured}</dt>
                    <dd>{voice.stt_configured ? t.yes : t.no}</dd>
                </div>
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.ttsConfigured}</dt>
                    <dd>{voice.tts_configured ? t.yes : t.no}</dd>
                </div>
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.openaiConfigured}</dt>
                    <dd>{voice.openai_configured ? t.yes : t.no}</dd>
                </div>
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2">
                    <dt>{t.elevenConfigured}</dt>
                    <dd>{voice.elevenlabs_configured ? t.yes : t.no}</dd>
                </div>
            </dl>

            <div className="space-y-3 rounded-lg border border-[#E6DCC8] bg-white/60 p-3">
                <h3 className="text-sm font-semibold text-slate-900">{t.elevenSection}</h3>
                <p className="text-sm text-slate-600">{t.elevenHelp}</p>
                {voice.elevenlabs_key_source === 'env' && <p className="text-xs text-slate-500">{t.elevenEnv}</p>}
                {voice.elevenlabs_key_source === 'admin' && <p className="text-xs text-slate-500">{t.elevenAdmin}</p>}
                <form onSubmit={saveKey} className="flex flex-wrap gap-2">
                    <input
                        type="password"
                        autoComplete="off"
                        value={elevenKey}
                        onChange={(event) => setElevenKey(event.target.value)}
                        placeholder={t.elevenKey}
                        className="min-w-[16rem] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    />
                    <button type="submit" disabled={busy !== null || elevenKey.length < 8} className="rounded-lg bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-60">
                        {t.elevenSave}
                    </button>
                    <button type="button" disabled={busy !== null || !voice.elevenlabs_configured} onClick={clearKey} className="rounded-lg border border-slate-300 px-3 py-2 text-sm disabled:opacity-60">
                        {t.elevenClear}
                    </button>
                </form>
            </div>

            <div className="space-y-3 rounded-lg border border-[#E6DCC8] bg-white/60 p-3">
                <h3 className="text-sm font-semibold text-slate-900">{t.realtime}</h3>
                <p className="text-sm text-slate-600">{t.realtimeHelp}</p>
                <div className="flex justify-between gap-3 rounded-lg bg-white/70 px-3 py-2 text-sm text-slate-700">
                    <dt>{t.realtime}</dt>
                    <dd>{voice.realtime?.configured ? t.realtimeConfigured : t.realtimeNotConfigured}</dd>
                </div>
            </div>

            <div>
                <h3 className="text-sm font-semibold text-slate-900">{t.limits}</h3>
                <ul className="mt-2 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">
                    {Object.entries(limits).map(([key, value]) => (
                        <li key={key}>{key}: {String(value)}</li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
