import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function GoogleOAuthConfigForm() {
    const { integrations = {}, errors = {}, locale = 'en' } = usePage().props;
    const google = integrations.google_oauth ?? {};
    const text = {
        en: {
            title: 'Google Configuration',
            clientId: 'Client ID',
            clientSecret: 'Client Secret',
            redirectUri: 'Redirect URI',
            envClient: 'Using deployment configuration. Saving a Client ID here takes precedence.',
            secretSaved: 'Secret saved',
            usingDeploy: 'Using deployment configuration',
            notSet: 'Not set',
            keepSecret: 'Leave blank to keep the current secret.',
            effectiveDefault: 'Effective default (readonly hint)',
            save: 'Save Google configuration',
        },
        ru: {
            title: 'Настройки Google',
            clientId: 'Client ID',
            clientSecret: 'Client Secret',
            redirectUri: 'Redirect URI',
            envClient: 'Используется конфигурация сервера. Client ID, сохранённый здесь, имеет приоритет.',
            secretSaved: 'Секрет сохранён',
            usingDeploy: 'Используется конфигурация сервера',
            notSet: 'Не задан',
            keepSecret: 'Оставьте пустым, чтобы сохранить текущий секрет.',
            effectiveDefault: 'Действующее значение по умолчанию',
            save: 'Сохранить настройки Google',
        },
        uk: {
            title: 'Налаштування Google',
            clientId: 'Client ID',
            clientSecret: 'Client Secret',
            redirectUri: 'Redirect URI',
            envClient: 'Використовується конфігурація сервера. Client ID, збережений тут, має пріоритет.',
            secretSaved: 'Секрет збережено',
            usingDeploy: 'Використовується конфігурація сервера',
            notSet: 'Не задано',
            keepSecret: 'Залиште порожнім, щоб зберегти поточний секрет.',
            effectiveDefault: 'Дійсне значення за замовчуванням',
            save: 'Зберегти налаштування Google',
        },
    };
    const t = text[locale] ?? text.en;
    const [clientId, setClientId] = useState(google.client_id ?? '');
    const [clientSecret, setClientSecret] = useState('');
    const [redirectUri, setRedirectUri] = useState(google.redirect_uri ?? '');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setClientId(google.client_id ?? '');
        setRedirectUri(google.redirect_uri ?? '');
        setClientSecret('');
    }, [google.client_id, google.redirect_uri, google.client_secret_source, google.configured]);

    const secretHint = google.client_secret_source === 'admin'
        ? t.secretSaved
        : google.client_secret_source === 'env'
            ? t.usingDeploy
            : t.notSet;

    const save = (event) => {
        event.preventDefault();
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(route('settings.integrations.google.update'), {
            client_id: clientId,
            client_secret: clientSecret,
            redirect_uri: redirectUri,
        }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onSuccess: () => setClientSecret(''),
        });
    };

    return (
        <form onSubmit={save} className="mt-4 space-y-3 border-t border-[#E6DCC8] pt-4">
            <h3 className="text-sm font-semibold text-slate-900">{t.title}</h3>
            <label className="block text-sm text-slate-700">
                {t.clientId}
                <input
                    type="text"
                    name="client_id"
                    autoComplete="off"
                    value={clientId}
                    onChange={(event) => setClientId(event.target.value)}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            {errors.client_id ? <p className="text-sm text-red-700">{errors.client_id}</p> : null}
            {google.client_id_source === 'env' ? (
                <p className="text-xs text-slate-500">{t.envClient}</p>
            ) : null}

            <label className="block text-sm text-slate-700">
                {t.clientSecret}
                <input
                    type="password"
                    name="client_secret"
                    autoComplete="new-password"
                    value={clientSecret}
                    onChange={(event) => setClientSecret(event.target.value)}
                    placeholder={google.has_client_secret ? '••••••••••••' : ''}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            <p className="text-xs text-slate-500">{secretHint}. {t.keepSecret}</p>
            {errors.client_secret ? <p className="text-sm text-red-700">{errors.client_secret}</p> : null}

            <label className="block text-sm text-slate-700">
                {t.redirectUri}
                <input
                    type="url"
                    name="redirect_uri"
                    autoComplete="off"
                    value={redirectUri}
                    onChange={(event) => setRedirectUri(event.target.value)}
                    placeholder={google.redirect_uri_default || ''}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                />
            </label>
            {google.redirect_uri_source !== 'admin' ? (
                <p className="text-xs text-slate-500">
                    {t.effectiveDefault}: {google.redirect_uri_effective}
                </p>
            ) : null}
            {errors.redirect_uri ? <p className="text-sm text-red-700">{errors.redirect_uri}</p> : null}

            <button
                type="submit"
                disabled={busy}
                className="inline-flex h-9 items-center rounded-lg bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
            >
                {t.save}
            </button>
        </form>
    );
}
