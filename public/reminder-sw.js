self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = {};
    }

    const title = typeof data.title === 'string' && data.title !== '' ? data.title : 'LAVR';
    const body = typeof data.body === 'string' ? data.body.slice(0, 180) : 'Напоминание';
    const url = safeUrl(data.url);

    event.waitUntil(
        self.registration.showNotification(title, {
            body,
            data: {
                url,
                reminder_id: data.reminder_id ?? null,
            },
            tag: data.reminder_id ? `reminder-${data.reminder_id}` : 'jarvis-reminder',
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = safeUrl(event.notification.data?.url);
    const reminderId = event.notification.data?.reminder_id ?? null;

    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            for (const client of clients) {
                if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                    client.postMessage({ type: 'open-reminder', url, reminderId });
                    await client.focus();

                    return;
                }
            }

            await self.clients.openWindow(url);
        })(),
    );
});

function safeUrl(value) {
    if (typeof value !== 'string' || value === '' || value.includes('://') || value.startsWith('//')) {
        return '/lavr';
    }

    try {
        const parsed = new URL(value, self.location.origin);

        if (parsed.origin !== self.location.origin) {
            return '/lavr';
        }

        const path = parsed.pathname;

        if (
            path === '/lavr'
            || path === '/jarvis'
            || path === '/chat'
            || path.startsWith('/lavr/')
            || path.startsWith('/jarvis/')
            || path.startsWith('/chat/')
        ) {
            return `${path}${parsed.search}`;
        }
    } catch {
        return '/lavr';
    }

    return '/lavr';
}
