import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function statusClass(status) {
    if (status === 'ready') {
        return 'bg-emerald-100 text-emerald-700';
    }
    if (status === 'partial' || status === 'not_configured') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
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
            title: 'Voice / Speech',
            hint: 'Технические настройки STT/TTS. Conversation AI не меняется. Test Connection нет. Телефония вне scope.',
            status: 'Status',
            stt: 'STT provider',
            tts: 'TTS provider',
            sttModel: 'STT model',
            none: 'None',
            gemini: 'Gemini',
            openai: 'OpenAI',
            elevenlabs: 'ElevenLabs',
            geminiSection: 'Gemini STT',
            geminiHelp: 'Используется существующий Gemini credential из AI Settings. Второго API key нет. Транскрипция — не Conversation AI.',
            geminiConfigured: 'Gemini configured',
            geminiCredential: 'Credential reused from AI Settings',
            geminiNoKey: 'Поля Gemini API key в Voice/Speech нет. Gemini настраивается в AI provider credentials.',
            spoken: 'Spoken-style presentation hint',
            spokenHelp: 'Ограниченная подсказка для устной речи. Это не второй personality prompt.',
            telegramTtsSpeed: 'Telegram TTS speed',
            telegramTtsSpeedHelp: 'Скорость голосовых ответов LAVR в Telegram. 1.00 — обычная скорость ElevenLabs.',
            save: 'Save settings',
            sttConfigured: 'STT configured',
            ttsConfigured: 'TTS configured',
            openaiConfigured: 'OpenAI configured',
            elevenConfigured: 'ElevenLabs configured',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Зашифрованный ключ только для TTS. Conversation AI остаётся на текущих AI settings.',
            elevenKey: 'API key (set or replace)',
            elevenSave: 'Save ElevenLabs key',
            elevenClear: 'Remove stored key',
            elevenEnv: 'Используется env ELEVENLABS_API_KEY. Сохранённый здесь ключ имеет приоритет.',
            elevenAdmin: 'Configured from Admin.',
            openaiHelp: 'OpenAI Whisper использует существующий OpenAI ключ из AI provider settings. Не через Conversation AI chat.',
            yes: 'yes',
            no: 'no',
            limits: 'Hard bounds (config)',
            realtime: 'Realtime Conversation',
            realtimeHelp: 'Только Web «Диалог Beta». Telegram и Рация без изменений. Env: ELEVENLABS_REALTIME_ENABLED, ELEVENLABS_AGENT_ID, ELEVENLABS_CUSTOM_LLM_SECRET.',
            realtimeConfigured: 'Configured',
            realtimeNotConfigured: 'Not configured',
        },
        uk: {
            title: 'Voice / Speech',
            hint: 'Технічні налаштування STT/TTS. Conversation AI не змінюється. Test Connection немає. Телефонія поза scope.',
            status: 'Status',
            stt: 'STT provider',
            tts: 'TTS provider',
            sttModel: 'STT model',
            none: 'None',
            gemini: 'Gemini',
            openai: 'OpenAI',
            elevenlabs: 'ElevenLabs',
            geminiSection: 'Gemini STT',
            geminiHelp: 'Використовується наявний Gemini credential з AI Settings. Другого API key немає. Транскрипція — не Conversation AI.',
            geminiConfigured: 'Gemini configured',
            geminiCredential: 'Credential reused from AI Settings',
            geminiNoKey: 'Поля Gemini API key у Voice/Speech немає. Gemini налаштовується в AI provider credentials.',
            spoken: 'Spoken-style presentation hint',
            spokenHelp: 'Обмежена підказка для усного мовлення. Це не другий personality prompt.',
            telegramTtsSpeed: 'Telegram TTS speed',
            telegramTtsSpeedHelp: 'Скорость голосовых ответов LAVR в Telegram. 1.00 — обычная скорость ElevenLabs.',
            save: 'Save settings',
            sttConfigured: 'STT configured',
            ttsConfigured: 'TTS configured',
            openaiConfigured: 'OpenAI configured',
            elevenConfigured: 'ElevenLabs configured',
            elevenSection: 'ElevenLabs',
            elevenHelp: 'Зашифрований ключ лише для TTS. Conversation AI лишається на поточних AI settings.',
            elevenKey: 'API key (set or replace)',
            elevenSave: 'Save ElevenLabs key',
            elevenClear: 'Remove stored key',
            elevenEnv: 'Використовується env ELEVENLABS_API_KEY. Збережений тут ключ має пріоритет.',
            elevenAdmin: 'Configured from Admin.',
            openaiHelp: 'OpenAI Whisper використовує наявний OpenAI ключ з AI provider settings. Не через Conversation AI chat.',
            yes: 'yes',
            no: 'no',
            limits: 'Hard bounds (config)',
            realtime: 'Realtime Conversation',
            realtimeHelp: 'Лише Web «Диалог Beta». Telegram і Рація без змін. Env: ELEVENLABS_REALTIME_ENABLED, ELEVENLABS_AGENT_ID, ELEVENLABS_CUSTOM_LLM_SECRET.',
            realtimeConfigured: 'Configured',
            realtimeNotConfigured: 'Not configured',
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
                    {voice.status_label ?? t.none}
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
