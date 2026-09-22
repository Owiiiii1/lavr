import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import IntegrationProviderCard from './IntegrationProviderCard';
import SettingsPanelHeader from './SettingsPanelHeader';
import ZoomConfigForm from './ZoomConfigForm';

export default function ZoomPanel({ t, status }) {
    const { integrations = {} } = usePage().props;
    const provider = (integrations.providers ?? []).find((item) => item.provider === 'zoom');
    const [disconnecting, setDisconnecting] = useState(null);

    const disconnectProvider = () => {
        if (disconnecting || !window.confirm(t.disconnectConfirm)) {
            return;
        }

        setDisconnecting('zoom');
        router.post(route('settings.integrations.zoom.disconnect'), {}, {
            preserveScroll: true,
            onFinish: () => setDisconnecting(null),
        });
    };

    return (
        <div className="space-y-4">
            <SettingsPanelHeader title={t.tabZoom} hint={t.hintZoom} status={status} t={t} />

            {provider ? (
                <IntegrationProviderCard
                    provider={provider}
                    t={t}
                    disconnecting={disconnecting}
                    onDisconnect={disconnectProvider}
                >
                    <ZoomConfigForm />
                </IntegrationProviderCard>
            ) : (
                <section className="rounded-xl border border-[#E6DCC8] bg-[#FBF8F1] p-4">
                    <ZoomConfigForm />
                </section>
            )}
        </div>
    );
}
