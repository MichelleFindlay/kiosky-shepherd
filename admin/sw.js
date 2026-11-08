// Kiosky Shepherd service worker: shows alert notifications pushed by the server.
// It caches nothing, so the console always loads fresh from the server.
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Kiosky Shepherd', {
    body: d.body || '',
    icon: 'img/logo-192.png',
    badge: 'img/logo-128.png',
    tag: d.tag || 'kiosky-shepherd',
    renotify: true,
    data: { url: d.url || '/admin/' },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/admin/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) {
      if (c.url.includes('/admin/') && 'focus' in c) return c.focus();
    }
    return self.clients.openWindow(url);
  }));
});
