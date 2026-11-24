(() => {
  'use strict';
  const led = document.querySelector('[data-device-indicator]');
  const label = document.querySelector('[data-device-label]');
  if (!led || !label) return;
  let busy = false;
  async function refreshStatus() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const response = await fetch('api.php?status=1', {headers:{Accept:'application/json'},cache:'no-store'});
      if (!response.ok) throw new Error('status unavailable');
      const status = await response.json();
      if (!status.ok) throw new Error('status unavailable');
      const online = status.device_online === true;
      led.classList.toggle('is-online', online);
      led.classList.toggle('is-offline', !online);
      label.textContent = online ? 'Connected' : 'Disconnected';
      const lastSeen = status.last_seen ? new Date(String(status.last_seen).replace(' ', 'T')) : null;
      const title = lastSeen ? `Last reading received ${lastSeen.toLocaleString()}` : 'No ESP32 reading received yet';
      led.title = title;
      label.title = title;
    } catch {
      // A failed status request means the server status is unknown, not that the ESP32 stopped sending.
      label.textContent = 'Status unavailable';
      led.title = 'Could not reach the local server';
      label.title = led.title;
    } finally { busy = false; }
  }
  void refreshStatus();
  setInterval(refreshStatus, 2500);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) void refreshStatus(); });
})();
