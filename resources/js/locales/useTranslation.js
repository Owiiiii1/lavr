import { catalogs, DEFAULT_LOCALE, SUPPORTED_LOCALES } from '@/locales/catalogs';
import { usePage } from '@inertiajs/react';

function lookup(tree, path) {
    return path.split('.').reduce((current, part) => {
        if (current && typeof current === 'object' && part in current) {
            return current[part];
        }

        return undefined;
    }, tree);
}

function interpolate(value, replace) {
    return Object.keys(replace).reduce(
        (result, key) => result.replaceAll(`:${key}`, String(replace[key])),
        value,
    );
}

export function normalizeLocale(value) {
    const code = String(value || '').toLowerCase().trim();

    return SUPPORTED_LOCALES.includes(code) ? code : DEFAULT_LOCALE;
}

export function translate(locale, key, replace = {}) {
    const code = normalizeLocale(locale);
    const primary = lookup(catalogs[code], key);
    const fallback = lookup(catalogs[DEFAULT_LOCALE], key);
    const value = typeof primary === 'string' ? primary : fallback;

    if (typeof value !== 'string') {
        if (typeof console !== 'undefined') {
            console.warn('Missing translation key', key);
        }

        return key;
    }

    return interpolate(value, replace);
}

export function useTranslation() {
    const page = usePage();
    const locale = normalizeLocale(page.props.locale);
    const assistantLocale = normalizeLocale(page.props.assistantLocale);
    const supportedLocales = Array.isArray(page.props.supportedLocales) && page.props.supportedLocales.length > 0
        ? page.props.supportedLocales.filter((code) => SUPPORTED_LOCALES.includes(code))
        : SUPPORTED_LOCALES;
    const bcp47 = { uk: 'uk-UA', en: 'en-US', ru: 'ru-RU' }[locale];

    return {
        locale,
        assistantLocale,
        supportedLocales,
        bcp47,
        t: (key, replace = {}) => translate(locale, key, replace),
    };
}
