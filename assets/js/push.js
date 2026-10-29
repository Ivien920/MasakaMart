/**
 * MasakaMart — Web Push opt-in. Included from footer.php only when the
 * customer is logged in AND the server has a VAPID public key configured
 * (window.KATALE_VAPID_PUBLIC_KEY). Never asks for permission automatically
 * on page load — see katale_initPush(), called from a real button click.
 */

function katale_urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i++) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

function katale_pushSupported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * Call this from a button's onclick — e.g. a "Turn on notifications" toggle
 * in the profile page. Safe to call multiple times; it no-ops once already
 * subscribed and denied permission is never re-prompted.
 */
async function katale_initPush() {
  if (!katale_pushSupported()) {
    console.warn('[push] Not supported in this browser.');
    return { ok: false, reason: 'unsupported' };
  }
  if (!window.KATALE_VAPID_PUBLIC_KEY) {
    console.warn('[push] Server has no VAPID key configured yet.');
    return { ok: false, reason: 'not_configured' };
  }
  if (Notification.permission === 'denied') {
    // Never re-prompt — the browser would silently ignore it anyway, but
    // this also avoids nagging a user who has already said no.
    return { ok: false, reason: 'denied' };
  }

  try {
    const registration = await navigator.serviceWorker.register('/sw.js');

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      return { ok: false, reason: permission };
    }

    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: katale_urlBase64ToUint8Array(window.KATALE_VAPID_PUBLIC_KEY),
      });
    }

    const response = await fetch(APP_BASE_URL + '/notifications/push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: window.KATALE_CSRF_TOKEN }, subscription.toJSON())),
    });
    const result = await response.json();
    if (!result.ok) {
      console.warn('[push] Server rejected the subscription:', result.error);
      return { ok: false, reason: result.error || 'server_error' };
    }
    return { ok: true };
  } catch (err) {
    console.error('[push] Subscription failed:', err);
    return { ok: false, reason: 'exception' };
  }
}

async function katale_disablePush() {
  if (!katale_pushSupported()) return;
  try {
    const registration = await navigator.serviceWorker.getRegistration('/sw.js');
    if (!registration) return;
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) return;

    await fetch(APP_BASE_URL + '/notifications/push_unsubscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: window.KATALE_CSRF_TOKEN, endpoint: subscription.endpoint }),
    });
    await subscription.unsubscribe();
  } catch (err) {
    console.error('[push] Unsubscribe failed:', err);
  }
}
