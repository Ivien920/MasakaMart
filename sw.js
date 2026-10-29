// MasakaMart service worker — Web Push only. Registered at root scope
// (see assets/js/push.js) so it can receive push events site-wide.
// Deliberately does NOT do offline caching — that is a separate concern
// this task did not ask for, and adding it would risk stale pages.

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let data = { title: 'MasakaMart', body: 'You have a new update.', url: '/' };
  try {
    if (event.data) {
      data = Object.assign(data, event.data.json());
    }
  } catch (e) {
    // Non-JSON payload — fall back to the defaults above rather than fail silently.
  }

  // No branded icon file exists in this project yet — the browser falls
  // back to a generic icon. Add an `icon: '/assets/images/...'` line here
  // once you have a real MasakaMart app icon PNG.
  const options = {
    body: data.body,
    data: { url: data.url || '/' },
  };

  event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if (client.url === targetUrl && 'focus' in client) {
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }
    })
  );
});
