(() => {
  'use strict';
  const dataNode = document.getElementById('climate-data');
  if (!dataNode) return;
  let data;
  try { data = JSON.parse(dataNode.textContent); } catch { return; }
  let rows = Array.isArray(data.rows) ? data.rows.slice() : [];
  let latestId = Number(data.latestId) || 0;
  let total = Number(data.total) || 0;
  let apiConnected = Boolean(data.dbOnline);
  let busy = false;
  const $ = (selector) => document.querySelector(selector);
  const n = (value, digits = 1) => value === null || value === '' || !Number.isFinite(Number(value)) ? '—' : Number(value).toFixed(digits);
  const safe = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const date = (value) => new Date(String(value).replace(' ', 'T'));
  const stamp = (value) => date(value).toLocaleTimeString([], {hour12:false});
  const dateLabel = (value) => date(value).toLocaleDateString([], {month:'short',day:'numeric',year:'numeric'});
  let latestAt = rows[0]?.recorded_at ? date(rows[0].recorded_at).getTime() : 0;

  function renderTable() {
    const body = $('#climateRows');
    if (!body) return;
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="7" class="climate-empty">No climate readings recorded yet. The page will add them as soon as the ESP32 sends data.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(row => {
      const rain = !!Number(row.rain_detected);
      const alert = ['normal','watch','warning','danger'].includes(String(row.alert_level)) ? String(row.alert_level) : 'normal';
      return `<tr class="${Number(row.id) === latestId ? 'climate-new-row' : ''}"><td class="timestamp-cell"><span class="row-led ${alert==='danger'?'row-led-danger':alert==='warning'?'row-led-warning':''}"></span><span>${safe(dateLabel(row.recorded_at))}</span><span class="timestamp-time">${safe(stamp(row.recorded_at))}</span></td><td><strong class="table-water-value">${n(row.water_level_cm)}<span> cm</span></strong></td><td>${n(row.distance_cm)}<span class="unit-label"> cm</span></td><td>${n(row.temperature_c)}<span class="unit-label"> °C</span></td><td>${n(row.humidity_pct,0)}<span class="unit-label">%</span></td><td><span class="table-rain ${rain?'rain-active':''}"><span class="rain-led"></span>${rain?'Detected':'None'}</span></td><td><span class="status-pill status-${alert}"><span></span>${alert.charAt(0).toUpperCase()+alert.slice(1)}</span></td></tr>`;
    }).join('');
    const latest = rows[0];
    $('#climateShown').textContent = String(rows.length);
    if (latest) {
      $('#climateTemperature').innerHTML = `${n(latest.temperature_c)}<span>°C</span>`;
      $('#climateHumidity').innerHTML = `${n(latest.humidity_pct,0)}<span>%</span>`;
      $('#climateRain').textContent = Number(latest.rain_detected) ? 'Detected' : 'No rain';
      $('#climateTimestamp').textContent = stamp(latest.recorded_at);
      $('#climateDate').textContent = dateLabel(latest.recorded_at);
      $('#climateFooterTime').textContent = String(latest.recorded_at);
    }
  }

  function updateFreshness() {
    if (!apiConnected) return;
    const fresh = latestAt > 0 && Date.now() - latestAt <= 5000;
    $('#liveState')?.classList.toggle('is-stale', !fresh);
    if ($('#liveText')) $('#liveText').textContent = fresh ? 'LIVE' : 'WAITING FOR DATA';
    if ($('#climateFooterStatus')) $('#climateFooterStatus').textContent = fresh ? 'Receiving live ESP32 readings' : 'No new ESP32 reading in the last few seconds';
    $('#climateFooterLed')?.classList.toggle('is-online', fresh);
    $('#climateFooterLed')?.classList.toggle('is-offline', !fresh);
  }

  async function poll() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const response = await fetch(`api.php?since_id=${encodeURIComponent(latestId)}`, {headers:{Accept:'application/json'},cache:'no-store'});
      if (!response.ok) throw new Error('Server unavailable');
      const result = await response.json();
      if (!result.ok || !Array.isArray(result.readings)) throw new Error('Invalid server response');
      apiConnected = true;
      $('#serverLed')?.classList.remove('is-offline');
      $('#serverLed')?.classList.add('is-online');
      if ($('#serverState')) $('#serverState').textContent = 'Local server';
      if (typeof result.total === 'number' && Number.isFinite(result.total)) total = result.total;
      if (result.readings.length) {
        const received = result.readings.sort((a,b) => Number(a.id) - Number(b.id));
        latestId = Math.max(latestId, ...received.map(row => Number(row.id) || 0));
        rows = received.reverse().concat(rows).slice(0,50);
        latestAt = date(rows[0].recorded_at).getTime();
        total += result.total == null ? result.readings.length : 0;
        renderTable();
        $('#liveState')?.classList.add('has-update');
        setTimeout(() => $('#liveState')?.classList.remove('has-update'), 900);
      }
      updateFreshness();
      $('#climateCount').textContent = total.toLocaleString();
      $('#climateTotal').textContent = total.toLocaleString();
    } catch {
      apiConnected = false;
      $('#serverLed')?.classList.remove('is-online');
      $('#serverLed')?.classList.add('is-offline');
      if ($('#serverState')) $('#serverState').textContent = 'Connection lost';
      $('#climateFooterStatus').textContent = 'Cannot reach MySQL right now';
      $('#climateFooterLed')?.classList.remove('is-online');
      $('#climateFooterLed')?.classList.add('is-offline');
    } finally { busy = false; }
  }

  const clock = () => { const el = $('#clock'); if (el) el.textContent = new Intl.DateTimeFormat(undefined,{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date()); };
  renderTable();
  updateFreshness();
  clock();
  setInterval(clock, 1000);
  setInterval(updateFreshness, 1000);
  setInterval(poll, 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) void poll(); });
  $('#menuButton')?.addEventListener('click', () => $('#sidebar')?.classList.toggle('sidebar-open'));
  document.addEventListener('click', event => { if (innerWidth <= 650 && !event.target.closest('#sidebar,#menuButton')) $('#sidebar')?.classList.remove('sidebar-open'); });
})();
