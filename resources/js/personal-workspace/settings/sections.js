export const SETTINGS_SECTIONS = [
    'profile',
    'assistant',
    'memory',
    'knowledge',
    'owner-context',
    'productivity',
    'voice',
    'integrations',
];

export function allowedSettingsSection(value) {
    const key = String(value || '').trim().toLowerCase();

    return SETTINGS_SECTIONS.includes(key) ? key : null;
}
