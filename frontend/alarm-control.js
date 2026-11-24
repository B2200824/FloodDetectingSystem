(() => {
  'use strict';
  const buttons = [...document.querySelectorAll('.alarm-mode')];
  if (!buttons.length) return;

  const labels = {auto:'Auto', on:'Siren ON', silence:'Silence'};
  const status = document.getElementById('alarmControlStatus');
  const endpoint = `${(window.FLOOD_CONFIG || {}).site || ''}/alarm-control.php`;
  const selectMode = (mode) => buttons.forEach(button => {
    const selected = button.dataset.alarmMode === mode;
    button.classList.toggle('is-selected', selected);
    button.setAttribute('aria-pressed', String(selected));
  });

  async function request(method, body) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(endpoint, {
        method,
        headers: method === 'POST'
          ? {'Content-Type':'application/json', Accept:'application/json'}
          : {Accept:'application/json'},
        body: body ? JSON.stringify(body) : undefined,
        cache:'no-store',
        signal:controller.signal
      });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.error || 'The alarm control request failed.');
      return result;
    } finally {
      window.clearTimeout(timeout);
    }
  }

  buttons.forEach(button => button.addEventListener('click', async () => {
    buttons.forEach(item => { item.disabled = true; });
    if (status) status.textContent = `Sending ${labels[button.dataset.alarmMode]} to the ESP32…`;
    try {
      const result = await request('POST', {mode:button.dataset.alarmMode});
      selectMode(result.mode);
      if (status) status.textContent = `${labels[result.mode]} sent to the ESP32.`;
    } catch (error) {
      if (status) status.textContent = error.name === 'AbortError'
        ? 'The request timed out. Check the server and Blynk connection.'
        : (error.message || 'Could not reach the alarm control endpoint.');
    } finally {
      buttons.forEach(item => { item.disabled = false; });
    }
  }));

  request('GET').then(result => {
    selectMode(result.mode);
    if (status) status.textContent = `${labels[result.mode]} is the current ESP32 alarm mode.`;
  }).catch(error => {
    if (status) status.textContent = error.name === 'AbortError'
      ? 'Reading the current mode timed out. Buttons are still available.'
      : `${error.message || 'Could not read the current mode.'} Buttons are still available.`;
  });
})();
