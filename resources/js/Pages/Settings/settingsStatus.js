import { translateKnown } from './integrationCopy';

export const READY = 'ready';
export const ATTENTION = 'attention';
export const ERROR = 'error';
export const OFF = 'off';
export const INFO = 'info';

/** Elements that count towards the "N of M working" summary. */
export const CONNECTION_ELEMENTS = ['ai', 'telegram', 'google', 'zoom', 'voice', 'web-research'];

const CHAT_PROVIDERS = ['openai', 'anthropic', 'gemini'];

export function badgeClass(state) {
    if (state === READY) {
        return 'bg-emerald-100 text-emerald-700';
    }
    if (state === ATTENTION) {
        return 'bg-amber-100 text-amber-800';
    }
    if (state === ERROR) {
        return 'bg-red-100 text-red-700';
    }

    return 'bg-slate-100 text-slate-700';
}

export function dotClass(state) {
    if (state === READY) {
        return 'bg-emerald-500';
    }
    if (state === ATTENTION) {
        return 'bg-amber-500';
    }
    if (state === ERROR) {
        return 'bg-red-500';
    }

    return 'bg-slate-300';
}

export function stateLabel(t, state) {
    if (state === READY) {
        return t.stateReady;
    }
    if (state === ATTENTION) {
        return t.stateAttention;
    }
    if (state === ERROR) {
        return t.stateError;
    }
    if (state === INFO) {
        return t.stateInfo;
    }

    return t.stateOff;
}

export function normalizeState(raw) {
    if (raw === 'connected' || raw === 'ready' || raw === 'enabled' || raw === 'configured') {
        return READY;
    }
    if (raw === 'error' || raw === 'revoked') {
        return ERROR;
    }
    if (raw === 'connecting' || raw === 'partial' || raw === 'incomplete') {
        return ATTENTION;
    }

    return OFF;
}

function flag(t, value) {
    return {
        value: value ? t.valueSet : t.valueMissing,
        state: value ? READY : OFF,
    };
}

/**
 * Single source of truth for connection status shown in the settings navigation and overview cards.
 *
 * @return array<string, array{state: string, detail: string, error: ?string, rows: array<int, array{label: string, value: string, state?: string}>}>
 */
export function deriveSettingsStatus({
    providers = [],
    aiRoles = [],
    telegram = {},
    webResearch = {},
    voice = {},
    integrations = {},
    locale = 'en',
    t,
}) {
    const tr = (value, fallback = '—') => translateKnown(locale, value) || fallback;
    const findProvider = (key) => providers.find((item) => item.provider === key);
    const googleAccounts = integrations.google_accounts ?? [];
    const telegramGroups = integrations.telegram_groups ?? [];
    const executions = integrations.recent_executions ?? [];

    return {
        ai: aiStatus({ providers, aiRoles, t }),
        telegram: telegramStatus({ telegram, telegramGroups, t }),
        google: googleStatus({ provider: findProvider('google'), googleAccounts, t, tr }),
        zoom: zoomStatus({ provider: findProvider('zoom'), t, tr }),
        voice: voiceStatus({ voice, t, tr }),
        'web-research': webResearchStatus({ webResearch, t, tr }),
        activity: {
            state: INFO,
            detail: `${executions.length}`,
            error: null,
            rows: [{ label: t.rowRecentRuns, value: `${executions.length}` }],
        },
    };
}

function aiStatus({ providers, aiRoles, t }) {
    const chatProviders = providers.filter((item) => CHAT_PROVIDERS.includes(item.provider));
    const connectedKeys = chatProviders.filter((item) => item.is_connected);
    const keyError = chatProviders.some((item) => item.last_error && !item.is_connected);
    const enabledRoles = aiRoles.filter((item) => item.is_enabled && item.provider && item.model);
    const ownerConversation = aiRoles.find((item) => item.role_key === 'owner_conversation');
    const ownerReady = Boolean(ownerConversation?.is_enabled && ownerConversation?.model);

    let state = OFF;
    if (ownerReady) {
        state = READY;
    } else if (aiRoles.some((item) => item.model) || connectedKeys.length > 0) {
        state = ATTENTION;
    } else if (keyError) {
        state = ERROR;
    }

    let detail = t.aiNoKeys;
    if (ownerReady) {
        detail = ownerConversation.model;
    } else if (connectedKeys.length > 0) {
        detail = t.aiNoModel;
    }

    const rows = [
        {
            label: t.rowKeys,
            value: `${connectedKeys.length} / ${chatProviders.length}`,
            state: connectedKeys.length > 0 ? READY : OFF,
        },
        ...aiRoles.map((role) => ({
            label: t.roleLabels[role.label] ?? role.label,
            value: role.is_enabled && role.model ? role.model : role.model ? t.valueOff : t.valueNone,
            state: role.is_enabled && role.model ? READY : role.model ? ATTENTION : OFF,
        })),
    ];

    return {
        state,
        detail,
        error: chatProviders.find((item) => item.last_error)?.last_error ?? null,
        rows,
        enabledRoles: enabledRoles.length,
    };
}

function telegramStatus({ telegram, telegramGroups, t }) {
    let state = OFF;
    if (telegram.last_error) {
        state = ERROR;
    } else if (telegram.is_webhook_set) {
        state = READY;
    } else if (telegram.has_bot_token) {
        state = ATTENTION;
    }

    return {
        state,
        detail: telegram.bot_username ? `@${telegram.bot_username}` : t.notConfigured,
        error: telegram.last_error ?? null,
        rows: [
            { label: t.rowToken, ...flag(t, telegram.has_bot_token) },
            { label: t.rowWebhook, ...flag(t, telegram.is_webhook_set) },
            { label: t.rowGroups, value: `${telegramGroups.length}` },
        ],
    };
}

function googleStatus({ provider, googleAccounts, t, tr }) {
    const gmail = googleAccounts.filter((account) => account.gmail_connected).length;
    const calendar = googleAccounts.filter((account) => account.calendar_connected).length;

    let state = normalizeState(provider?.state);
    if (state === READY && googleAccounts.length === 0) {
        state = ATTENTION;
    }
    if (state === OFF && provider?.configured) {
        state = ATTENTION;
    }

    return {
        state,
        detail: googleAccounts[0]?.email ?? provider?.account_label ?? t.notConfigured,
        error: provider?.diagnostic_message ? tr(provider.diagnostic_message, '') : null,
        rows: [
            { label: t.rowOauthClient, ...flag(t, provider?.configured) },
            {
                label: t.rowAccounts,
                value: `${googleAccounts.length}`,
                state: googleAccounts.length > 0 ? READY : OFF,
            },
            { label: t.rowGmail, value: `${gmail}`, state: gmail > 0 ? READY : OFF },
            { label: t.rowCalendar, value: `${calendar}`, state: calendar > 0 ? READY : OFF },
        ],
    };
}

function zoomStatus({ provider, t, tr }) {
    let state = normalizeState(provider?.state);
    if (state === OFF && provider?.configured) {
        state = ATTENTION;
    }

    return {
        state,
        detail: provider?.account_label ?? tr(provider?.oauth_client_label, t.notConfigured),
        error: provider?.diagnostic_message ? tr(provider.diagnostic_message, '') : null,
        rows: [
            { label: t.rowOauthClient, ...flag(t, provider?.configured) },
            {
                label: t.rowAccountStatus,
                value: tr(provider?.account_status_label, t.notConfigured),
                state: normalizeState(provider?.state),
            },
        ],
    };
}

function voiceStatus({ voice, t, tr }) {
    return {
        state: normalizeState(voice.status),
        detail: `${tr(voice.stt_provider_label)} · ${tr(voice.tts_provider_label)}`,
        error: null,
        rows: [
            {
                label: t.rowStt,
                value: tr(voice.stt_provider_label, t.valueOff),
                state: voice.stt_configured ? READY : OFF,
            },
            {
                label: t.rowTts,
                value: tr(voice.tts_provider_label, t.valueOff),
                state: voice.tts_configured ? READY : OFF,
            },
            { label: t.rowElevenLabs, ...flag(t, voice.elevenlabs_configured) },
        ],
    };
}

function webResearchStatus({ webResearch, t, tr }) {
    return {
        state: normalizeState(webResearch.status),
        detail: tr(webResearch.status_label, t.notConfigured),
        error: null,
        rows: [
            {
                label: t.rowSearchProvider,
                value: tr(webResearch.active_provider_label, t.valueOff),
                state: webResearch.provider_configured ? READY : OFF,
            },
            {
                label: t.rowAvailableToAssistant,
                value: webResearch.runtime_enabled ? t.valueOn : t.valueOff,
                state: webResearch.runtime_enabled ? READY : OFF,
            },
        ],
    };
}
