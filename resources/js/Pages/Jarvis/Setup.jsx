import LavrAppShell from '@/telegram/LavrAppShell';
import { useTranslation } from '@/locales/useTranslation';
import { Head, Link, router, useForm } from '@inertiajs/react';

export default function Setup({ map, profile, projects = [], people = [], locales = [] }) {
    const { t } = useTranslation();
    const step = map?.current_step || 'owner_profile';
    const form = useForm({
        step,
        name: profile?.name || '',
        timezone: profile?.timezone || 'Europe/Kyiv',
        interface_locale: profile?.interface_locale || 'uk',
        assistant_locale: profile?.assistant_locale || 'uk',
        assistant_name: profile?.assistant_name || 'LAVR',
        interaction_style: profile?.interaction_style || '',
        project_name: '',
        organization_name: '',
        display_name: '',
        primary_email: '',
        project_id: projects[0]?.id || '',
        person_id: people[0]?.id || '',
        morning_brief_enabled: true,
        operational_alerts_enabled: true,
        third_party_execute: false,
    });

    const submit = (nextStep) => {
        router.post(route('jarvis.setup.save'), { ...form.data, step: nextStep }, { preserveScroll: true });
    };

    return (
        <LavrAppShell>
            <Head title={t('setup.title')} />
            <div className="jarvis-workspace px-4 pb-8 pt-8 text-slate-100 sm:px-8">
                <h1 className="text-2xl font-semibold text-white">{t('setup.title')}</h1>
                <p className="mt-2 text-sm text-slate-400">{t('setup.intro')}</p>
                <p className="mt-2 text-sm text-amber-200">{t('setup.progress', { done: map?.completed_count ?? 0, total: map?.total ?? 9 })}</p>
                <p className="mt-1 text-xs text-slate-500">{t('setup.saveLater')}</p>

                <div className="mt-6 space-y-6">
                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_owner_profile')}</h2>
                        <label className="mt-3 block text-xs text-slate-400" htmlFor="setup-name">{t('settings.name')}</label>
                        <input id="setup-name" className="mt-1 min-h-11 w-full rounded-xl bg-black/30 px-3" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                        <label className="mt-3 block text-xs text-slate-400" htmlFor="setup-tz">{t('settings.timezone')}</label>
                        <input id="setup-tz" className="mt-1 min-h-11 w-full rounded-xl bg-black/30 px-3" value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} />
                        <label className="mt-3 block text-xs text-slate-400" htmlFor="setup-ui">{t('settings.uiLanguage')}</label>
                        <select id="setup-ui" className="mt-1 min-h-11 w-full rounded-xl bg-black/30 px-3" value={form.data.interface_locale} onChange={(event) => form.setData('interface_locale', event.target.value)}>
                            {locales.map((code) => <option key={code} value={code}>{code}</option>)}
                        </select>
                        <label className="mt-3 block text-xs text-slate-400" htmlFor="setup-assistant">{t('settings.assistantLanguage')}</label>
                        <select id="setup-assistant" className="mt-1 min-h-11 w-full rounded-xl bg-black/30 px-3" value={form.data.assistant_locale} onChange={(event) => form.setData('assistant_locale', event.target.value)}>
                            {locales.map((code) => <option key={`a-${code}`} value={code}>{code}</option>)}
                        </select>
                        <button type="button" className="mt-3 min-h-11 rounded-xl bg-sky-500 px-4 text-sm" onClick={() => submit('owner_profile')}>{t('common.save')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_business_contexts')}</h2>
                        <label className="mt-3 block text-xs text-slate-400" htmlFor="setup-project">{t('projects.title')}</label>
                        <input id="setup-project" className="mt-1 min-h-11 w-full rounded-xl bg-black/30 px-3" value={form.data.project_name} onChange={(event) => form.setData('project_name', event.target.value)} />
                        <label className="mt-3 block text-xs text-slate-400" htmlFor="setup-org">{t('more.organizations')}</label>
                        <input id="setup-org" className="mt-1 min-h-11 w-full rounded-xl bg-black/30 px-3" value={form.data.organization_name} onChange={(event) => form.setData('organization_name', event.target.value)} />
                        <button type="button" className="mt-3 min-h-11 rounded-xl bg-sky-500 px-4 text-sm" onClick={() => submit('business_contexts')}>{t('common.save')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_key_people')}</h2>
                        <label className="mt-3 block text-xs text-slate-400" htmlFor="setup-person">{t('people.title')}</label>
                        <input id="setup-person" className="mt-1 min-h-11 w-full rounded-xl bg-black/30 px-3" value={form.data.display_name} onChange={(event) => form.setData('display_name', event.target.value)} />
                        <button type="button" className="mt-3 min-h-11 rounded-xl bg-sky-500 px-4 text-sm" onClick={() => submit('key_people')}>{t('common.save')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_responsibilities')}</h2>
                        <p className="mt-2 text-xs text-slate-400">{t('setup.linkPersonProject')}</p>
                        <button type="button" className="mt-3 min-h-11 rounded-xl bg-sky-500 px-4 text-sm" onClick={() => submit('responsibilities')}>{t('common.save')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_sources')}</h2>
                        <p className="mt-2 text-sm text-slate-400">{t('setup.sourcesHint')}</p>
                        <Link href="/settings?tab=integrations" className="mt-3 inline-flex min-h-11 items-center text-sm text-sky-300">{t('setup.openIntegrations')}</Link>
                        <button type="button" className="ml-3 min-h-11 rounded-xl border border-white/10 px-4 text-sm" onClick={() => submit('sources')}>{t('setup.markDone')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_mappings')}</h2>
                        <p className="mt-2 text-sm text-slate-400">{t('setup.mappingsHint')}</p>
                        <button type="button" className="mt-3 min-h-11 rounded-xl border border-white/10 px-4 text-sm" onClick={() => submit('mappings')}>{t('setup.markDone')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_executive_brief')}</h2>
                        <button type="button" className="mt-3 min-h-11 rounded-xl bg-sky-500 px-4 text-sm" onClick={() => submit('executive_brief')}>{t('common.save')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_proactivity')}</h2>
                        <p className="mt-2 text-xs text-slate-400">{t('setup.noAutoSend')}</p>
                        <button type="button" className="mt-3 min-h-11 rounded-xl bg-sky-500 px-4 text-sm" onClick={() => submit('proactivity')}>{t('common.save')}</button>
                    </section>

                    <section className="rounded-2xl border border-white/10 p-4">
                        <h2 className="text-sm font-semibold">{t('setup.step_review')}</h2>
                        <button type="button" className="mt-3 min-h-11 rounded-xl bg-sky-500 px-4 text-sm" onClick={() => submit('review')}>{t('setup.finish')}</button>
                    </section>
                </div>
            </div>
        </LavrAppShell>
    );
}
