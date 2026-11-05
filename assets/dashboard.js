(() => {
  'use strict';
  const dataEl = document.getElementById('dashboard-data');
  const config = window.FLOOD_CONFIG || {};
  if (!dataEl) return;
  let data;
  try { data = JSON.parse(dataEl.textContent); } catch { return; }
  let allReadings = Array.isArray(data.readings) ? data.readings.slice() : [];
  let timer;
  const $ = (selector) => document.querySelector(selector);
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number = (value, digits = 1) => value == null || !Number.isFinite(Number(value)) ? '—' : Number(value).toFixed(digits);
  const timeLabel = (date) => new Intl.DateTimeFormat(undefined, {hour:'2-digit',minute:'2-digit',hour12:false}).format(date);

  function setClock() {
    const el = $('#clock');
    if (el) el.textContent = new Intl.DateTimeFormat(undefined, {hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date());
  }

  function renderChart() {
    const requested = Number($('#chartRange')?.value || 18);
    const source = allReadings.slice(-requested);
    const svg = $('#waterChart');
    const path = $('#chartPath');
    const area = $('#chartArea');
    const points = $('#chartPoints');
    const tickEl = $('#chartTimes');
    if (!svg || !path || !area || !points || !tickEl) return;
    const max = Number(data.maxDepth) || 25;
    // Match the chart axis and warning/danger threshold positions to the configured depth.
    const top = max;
    const xy = source.map((row, i) => ({
      x: source.length < 2 ? 450 : 8 + i * (884 / (source.length - 1)),
      y: 8 + (1 - Math.min(top, Math.max(0, Number(row.water_level_cm) || 0)) / top) * 196,
      row
    }));
    const line = xy.map((p, i) => `${i ? 'L' : 'M'} ${p.x.toFixed(2)} ${p.y.toFixed(2)}`).join(' ');
    path.setAttribute('d', line);
    area.setAttribute('d', xy.length ? `${line} L ${xy.at(-1).x.toFixed(2)} 220 L ${xy[0].x.toFixed(2)} 220 Z` : '');
    points.innerHTML = xy.map((p, i) => `<circle class="${i === xy.length - 1 ? 'chart-point chart-point-latest' : 'chart-point'}" cx="${p.x.toFixed(2)}" cy="${p.y.toFixed(2)}" r="${i === xy.length - 1 ? 4 : 2.6}"><title>${escapeHtml(new Date(String(p.row.recorded_at).replace(' ', 'T')).toLocaleString())}: ${number(p.row.water_level_cm)} cm</title></circle>`).join('');
    const ticks = [0, Math.floor((source.length - 1) / 2), Math.max(0, source.length - 1)];
    tickEl.innerHTML = ticks.map(i => {
      const r = source[i];
      return `<span>${r ? escapeHtml(timeLabel(new Date(String(r.recorded_at).replace(' ', 'T')))) : '—'}</span>`;
    }).join('');
    const latest = source.at(-1);
    svg.setAttribute('aria-label', latest ? `Latest water level ${number(latest.water_level_cm)} centimeters; ${source.length} sensor readings displayed` : 'No sensor readings available');
  }

  function refreshVisibleReading(readings) {
    const current = readings[0];
    const previous = readings[1];
    if (!current) return;
    const currentDate = new Date(String(current.recorded_at).replace(' ', 'T'));
    const previousDate = previous ? new Date(String(previous.recorded_at).replace(' ', 'T')) : currentDate;
    const minutes = Math.max((currentDate - previousDate) / 60000, 1 / 60);
    const level = Number(current.water_level_cm) || 0;
    const rate = previous ? (level - Number(previous.water_level_cm)) / minutes : 0;
    const limit = Number(config.maxDepthCm) || 25;
    const percentage = Math.min(100, Math.max(0, level / limit * 100));
    const set = (selector, value) => { const el = $(selector); if (el) el.textContent = value; };
    set('#waterLevel', number(level));
    set('#riseRate', number(rate, 2));
    set('#levelPercent', `${Math.round(percentage)}%`);
    set('#temperature', `${number(current.temperature_c)}°C`);
    set('#humidity', `${number(current.humidity_pct, 0)}%`);
    set('#lastUpdated', currentDate.toLocaleTimeString([], {hour12:false}));
    const fill = $('#levelFill');
    if (fill) fill.style.width = `${percentage}%`;
    const danger = Number(config.dangerDepthCm) || 22;
    const warning = Number(config.warningDepthCm) || 18;
    const eta = rate > .001 ? Math.max(0, (danger - level) / rate) : null;
    set('#timeToDanger', eta === null ? '—' : eta < 60 ? `${Math.round(eta)} min` : `${Math.floor(eta / 60)}h ${Math.round(eta % 60)}m`);
    const trend = $('#levelTrend');
    if (trend) {
      trend.className = `trend-badge ${rate > .01 ? 'trend-up' : rate < -.01 ? 'trend-down' : 'trend-steady'}`;
      trend.textContent = rate > .01 ? `↗ ${number(rate)} cm / min` : rate < -.01 ? `↘ ${number(rate)} cm / min` : 'Steady';
    }
    let status = String(current.alert_level || 'normal').toLowerCase();
    if (rate > .12 && level >= warning) status = 'danger';
    else if (level >= danger) status = 'danger';
    else if (level >= warning && status !== 'danger') status = 'warning';
    if (!['normal','watch','warning','danger'].includes(status)) status = 'normal';
    const titles = {normal:'All systems normal',watch:'Conditions to monitor',warning:'Rising water · Warning',danger:'Critical flood risk'};
    const banner = $('#alertBanner');
    if (banner) banner.className = `alert-banner alert-${status}`;
    set('#alertTitle', titles[status]);
    set('#alertPill', status.toUpperCase());
    set('#alertCaption', status === 'normal' ? 'Sensor readings are within normal operating ranges.' : `Water level ${rate >= 0 ? 'increasing' : 'decreasing'} · Last reading ${currentDate.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',hour12:false})}`);
    const rain = document.querySelector('.rain-status');
    if (rain) {
      const active = !!Number(current.rain_detected);
      rain.classList.toggle('rain-active', active);
      rain.innerHTML = `<span class="rain-led"></span> Rain ${active ? 'detected' : 'not detected'}`;
    }
    const chance = current.rain_probability_pct;
    const probabilityEl = $('.rain-probability');
    if (probabilityEl) probabilityEl.textContent = chance == null ? 'No forecast' : `${number(chance,0)}% chance`;
    const footer = $('.page-footer>span:first-child');
    if (footer) footer.innerHTML = `<span class="footer-led is-online"></span> Receiving sensor data`;
  }

  function renderTable(readings, total) {
    const body = $('#readingsBody');
    if (!body) return;
    const shown = readings.slice(0, 8);
    body.innerHTML = shown.map((row, i) => {
      const previous = readings[i + 1];
      const dt = new Date(String(row.recorded_at).replace(' ', 'T'));
      const priorDt = previous ? new Date(String(previous.recorded_at).replace(' ', 'T')) : dt;
      const rate = previous ? (Number(row.water_level_cm) - Number(previous.water_level_cm)) / Math.max((dt-priorDt)/60000,1/60) : 0;
      const status = ['normal','watch','warning','danger'].includes(String(row.alert_level)) ? row.alert_level : 'normal';
      const rain = !!Number(row.rain_detected);
      return `<tr><td class="timestamp-cell"><span class="row-led ${status==='warning'?'row-led-warning':status==='danger'?'row-led-danger':''}"></span><span>${escapeHtml(dt.toLocaleDateString(undefined,{month:'short',day:'numeric',year:'numeric'}))}</span><span class="timestamp-time">${escapeHtml(dt.toLocaleTimeString([], {hour12:false}))}</span></td><td><strong class="table-water-value">${number(row.water_level_cm)}<span> cm</span></strong></td><td class="rate-cell ${rate>.01?'rate-positive':rate<-.01?'rate-negative':''}">${Math.abs(rate)>.001?`${rate>0?'↗':'↘'} ${number(Math.abs(rate),2)} <span>cm/m</span>`:'—'}</td><td><span class="table-rain ${rain?'rain-active':''}"><span class="rain-led"></span>${rain?'Detected':'None'}</span></td><td>${number(row.temperature_c)}<span class="unit-label">°C</span></td><td>${number(row.humidity_pct,0)}<span class="unit-label">%</span></td><td><span class="status-pill status-${status}"><span></span>${status.charAt(0).toUpperCase()+status.slice(1)}</span></td></tr>`;
    }).join('');
    const count = $('#readingCount');
    if (count) count.textContent = String(total);
    const totalEl = $('#totalReadings');
    if (totalEl && totalEl.textContent !== '—') totalEl.textContent = String(total);
    const shownEl = $('.table-footer>span');
    if (shownEl) shownEl.innerHTML = `Showing <strong>${shown.length}</strong> of <strong id="totalReadings">${total}</strong> recent readings`;
  }

  function notify(message) {
    document.querySelector('.toast')?.remove();
    const el = document.createElement('div');
    el.className = 'toast'; el.setAttribute('role','status'); el.textContent = message;
    document.body.append(el);
    setTimeout(() => el.remove(), 2600);
  }

  async function refresh(manual = false) {
    const button = $('#refreshButton');
    if (button) button.disabled = true;
    try {
      const response = await fetch(`${config.site || ''}/api.php`, {headers:{Accept:'application/json'},cache:'no-store'});
      if (!response.ok) throw new Error('No live database connection');
      const result = await response.json();
      if (!result.ok || !Array.isArray(result.readings)) throw new Error(result.error || 'Invalid server response');
      allReadings = result.readings;
      if (allReadings.length) {
        data.demo = false;
        if (!data.dbOnline) location.reload();
        data.dbOnline = result.database_online;
        const pill = $('.local-pill');
        if (pill) pill.innerHTML = '<span class="status-led is-online"></span> Local server';
        const source = $('.data-source');
        if (source) source.innerHTML = '<span class="database-icon">◈</span> MySQL · Local database';
        const demoNotice = document.querySelector('.demo-notice');
        if (demoNotice) demoNotice.remove();
        refreshVisibleReading(allReadings);
        renderTable(allReadings, result.total);
        renderChart();
        if (manual) notify('Sensor readings refreshed.');
      } else if (manual) notify('No sensor readings have been recorded yet.');
    } catch (error) {
      if (manual) notify(error.message || 'Unable to refresh sensor readings.');
    } finally {
      if (button) button.disabled = false;
    }
  }

  function csvDownload() {
    if (data.demo || !data.dbOnline || !allReadings.length) { notify('Connect MySQL and your sensors to export recorded readings.'); return; }
    const columns = ['id','recorded_at','water_level_cm','distance_cm','temperature_c','humidity_pct','rain_detected','rain_probability_pct','alert_level'];
    const csv = [columns.join(','), ...allReadings.map(row => columns.map(key => {
      const value = row[key] ?? '';
      return `"${String(value).replaceAll('"','""')}"`;
    }).join(','))].join('\r\n');
    const url = URL.createObjectURL(new Blob(['\ufeff',csv],{type:'text/csv;charset=utf-8'}));
    const anchor = document.createElement('a'); anchor.href = url;
    anchor.download = `flood-readings-${new Date().toISOString().slice(0,10)}.csv`;
    anchor.click(); URL.revokeObjectURL(url);
  }

  const range = $('#chartRange');
  if (range) range.addEventListener('change', renderChart);
  $('#refreshButton')?.addEventListener('click', () => refresh(true));
  $('#downloadButton')?.addEventListener('click', csvDownload);
  $('#viewAllButton')?.addEventListener('click', () => {
    const readings = allReadings.length ? allReadings : [];
    if (readings.length) { renderTable(readings, data.demo ? readings.length : readings.length); $('#readings')?.scrollIntoView({behavior:'smooth',block:'center'}); }
    else notify('Start the MySQL database and send readings to view your history.');
  });
  $('#menuButton')?.addEventListener('click', () => $('#sidebar')?.classList.toggle('sidebar-open'));
  document.addEventListener('click', (event) => { if (innerWidth <= 650 && !event.target.closest('#sidebar,#menuButton')) $('#sidebar')?.classList.remove('sidebar-open'); });
  setClock(); setInterval(setClock, 1000);
  renderChart();
  timer = window.setInterval(() => refresh(false), Number(config.pollMs) || 15000);
  void timer;
})();
