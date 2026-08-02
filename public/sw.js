// QAZAQ TAS ERP — Service Worker для Web Push: уведомления чата приходят как в
// WhatsApp, даже когда браузер свёрнут или вкладка ERP закрыта.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = { title: '💬 Новое сообщение', body: '', url: '/chat' };
    try { data = { ...data, ...event.data.json() }; } catch (e) { /* пустой payload */ }
    event.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/logo-qazaqtas.png',
        badge: '/logo-qazaqtas.png',
        tag: 'qazaqtas-chat-push', // новое сообщение заменяет предыдущее уведомление
        data: { url: data.url },
    }));
});

// Клик по уведомлению: фокусируем открытую вкладку ERP или открываем чат.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/chat';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((tabs) => {
        const tab = tabs.find((t) => 'focus' in t);
        if (tab) { tab.focus(); return tab.navigate(url); }
        return self.clients.openWindow(url);
    }));
});
